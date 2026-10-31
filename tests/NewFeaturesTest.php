<?php

declare(strict_types=1);

namespace rapttor\yii2legal\tests;

use PHPUnit\Framework\TestCase;
use rapttor\yii2legal\blocklist\BlocklistInterface;
use rapttor\yii2legal\blocklist\PostarBlocklist;
use rapttor\yii2legal\Bootstrap;
use rapttor\yii2legal\console\LegalController as ConsoleController;
use rapttor\yii2legal\events\WithdrawalEvent;
use rapttor\yii2legal\filters\ConsentGate;
use rapttor\yii2legal\Legal;
use rapttor\yii2legal\Schema;
use rapttor\yii2legal\stores\JsonAttributeConsentStore;
use rapttor\yii2legal\widgets\CheckoutDisclosure;
use Yii;
use yii\db\ActiveRecord;

final class TestUserRecord extends ActiveRecord
{
    public static function tableName(): string
    {
        return 'user_t';
    }
}

final class NewFeaturesTest extends TestCase
{
    /** @var list<array<string,mixed>> */
    private array $mails = [];

    private const BASE = [
        'company' => ['name' => 'Acme d.o.o.', 'address' => '1 Main St, Town, Land', 'country' => 'Land', 'email' => 'hi@acme.test', 'website' => 'https://acme.test'],
        'product' => ['name' => 'Acme App'],
    ];

    /** @param bool $baseline false (default in these tests) switches the default-on consents off so a test sees only what it enables; true keeps the real defaults */
    private function legal(array $extra = [], array $props = [], bool $wipe = true, bool $baseline = false): Legal
    {
        $this->mails = [];
        if (!$baseline) {
            $extra = array_replace_recursive(['feature' => ['age_gate' => false, 'uploads' => false]], $extra);
        }
        $legal = new Legal(array_replace([
            'config' => array_replace_recursive(self::BASE, $extra), 'cache' => null, 'archivePath' => '@runtime/legal-' . uniqid(),
            'acceptanceTable' => 'legal_acceptance', 'suppressionTable' => 'legal_suppression',
            'tokenTable' => 'legal_token', 'withdrawalTable' => 'legal_withdrawal', 'requestTable' => 'legal_request', 'secret' => 'test-secret',
            'mailSender' => function (string $to, string $subject, string $html, string $text, array $headers = []): void {
                $this->mails[] = compact('to', 'subject', 'html', 'text', 'headers');
            },
        ], $props));
        Yii::$app->set('legal', $legal);
        Schema::create(Yii::$app->db, $legal);
        foreach ($wipe ? ['legal_acceptance', 'rapttor_metadata', 'legal_suppression', 'legal_token', 'legal_withdrawal', 'legal_request'] : [] as $t) {
            Yii::$app->db->createCommand()->delete($t)->execute();
        }
        return $legal;
    }

    protected function setUp(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Yii::$app->request->setBodyParams([]);
        Yii::$app->response->cookies->removeAll();
        Yii::$app->response->headers->removeAll();
        Yii::$app->response->statusCode = 200;
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $_COOKIE = [];
        $p = new \ReflectionProperty(\yii\web\Request::class, '_cookies');
        $p->setValue(Yii::$app->request, null);
    }

    private function post(array $params): void
    {
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Yii::$app->request->setBodyParams($params);
    }

    private function token(): string
    {
        $this->assertNotEmpty($this->mails, 'a confirmation email was sent');
        preg_match('/t=([a-f0-9]{48})/', $this->mails[array_key_last($this->mails)]['text'], $m);
        return $m[1] ?? '';
    }

    // ---- consent registry -------------------------------------------------------------------------------------

    public function testDefinitionsFollowTheFeatures(): void
    {
        $keys = fn(Legal $l): array => array_column($l->consents()->definitions(), 'key');
        $this->assertSame(['terms', 'privacy'], $keys($this->legal()));
        $legal = $this->legal(['feature' => ['age_gate' => true, 'uploads' => true, 'marketing' => true], 'policy' => ['age_gate' => '16', 'age_topics' => 'chat'],
            'data' => ['external' => [['key' => 'google_fonts', 'name' => 'Google Fonts', 'provider' => 'Google', 'country' => 'USA', 'purpose' => 'fonts']]]]);
        $this->assertSame(['terms', 'privacy', 'age', 'uploads', 'marketing', 'ext_google_fonts'], $keys($legal));
        $defs = array_column($legal->consents()->definitions(), null, 'key');
        $this->assertTrue($defs['age']['required']);
        $this->assertStringContainsString('at least 16 years old', $defs['age']['label']);
        $this->assertStringContainsString('chat', $defs['age']['hint'], 'the explanation sits in the hint, the label stays short');
        $this->assertFalse($defs['marketing']['required']);
        $this->assertFalse($defs['ext_google_fonts']['required']);
    }

    public function testRequiredConsentsBlockAndOptionalOnesAreAskedOncePerVersion(): void
    {
        $legal = $this->legal(['feature' => ['marketing' => true]]);
        $reg = $legal->consents();
        $this->assertCount(3, $reg->pending(5));
        $this->assertCount(2, $reg->blocking(5));

        $res = $reg->record(5, ['terms' => '1']);
        $this->assertFalse($res['ok']);
        $this->assertSame(['privacy'], $res['missing']);
        $this->assertCount(3, $reg->pending(5), 'nothing stored while a required item is missing');

        $this->assertTrue($reg->record(5, ['terms' => '1', 'privacy' => '1'])['ok'], 'marketing declined is allowed');
        $this->assertSame([], $reg->pending(5), 'the declined optional item is not asked again in the same version');
        $this->assertTrue($reg->granted(5, 'terms'));
        $this->assertFalse($reg->granted(5, 'marketing'));
        $this->assertFalse($reg->state(5)['marketing']['granted']);

        // A new version of an item asks again, only for that item.
        $legal = $this->legal(['feature' => ['marketing' => true]], ['consents' => [['key' => 'research', 'required' => false, 'version' => '1', 'label' => 'Research']]]);
        $legal->consents()->record(6, ['terms' => 1, 'privacy' => 1]);
        $legal2 = $this->legal(['feature' => ['marketing' => true]], ['consents' => [['key' => 'research', 'required' => false, 'version' => '2', 'label' => 'Research']]], false);
        $this->assertSame(['research'], array_column($legal2->consents()->pending(6), 'key'), 'marketing was already answered; only the changed item is asked again');
    }

    public function testConsentsCanBeKeptInAJsonAttributeOfTheUserModel(): void
    {
        Yii::$app->db->createCommand('DROP TABLE IF EXISTS user_t')->execute();
        Yii::$app->db->createCommand()->createTable('user_t', ['id' => 'pk', 'consents' => 'text NULL'])->execute();
        Yii::$app->db->createCommand()->insert('user_t', ['id' => 9])->execute();
        $legal = $this->legal([], ['consentStore' => ['class' => JsonAttributeConsentStore::class, 'modelClass' => TestUserRecord::class, 'attribute' => 'consents']]);
        $this->assertTrue($legal->consents()->record(9, ['terms' => 1, 'privacy' => 1])['ok']);
        $json = json_decode((string)Yii::$app->db->createCommand('SELECT consents FROM user_t WHERE id = 9')->queryScalar(), true);
        $this->assertTrue($json['terms']['granted']);
        $this->assertSame([], $legal->consents()->pending(9));
    }

    public function testConsentGateRedirectsUntilEverythingIsAnswered(): void
    {
        $legal = $this->legal();
        Yii::$app->user->setIdentity(new TestIdentity(['id' => 21]));
        $controller = new \yii\web\Controller('site', Yii::$app);
        $gate = new ConsentGate();
        $this->assertFalse($gate->beforeAction(new \yii\base\InlineAction('index', $controller, 'actionIndex')), 'pending consents: stop the action');
        $this->assertStringContainsString('/legal/consents', (string)Yii::$app->response->headers->get('Location'));
        $this->assertTrue($gate->beforeAction(new \yii\base\InlineAction('index', new \yii\web\Controller('legal', Yii::$app), 'actionIndex')), 'legal pages stay reachable');
        $legal->consents()->record(21, ['terms' => 1, 'privacy' => 1]);
        $this->assertTrue($gate->beforeAction(new \yii\base\InlineAction('index', $controller, 'actionIndex')));
    }

    public function testConsentsPageStoresAnswers(): void
    {
        $legal = $this->legal(['feature' => ['uploads' => true]]);
        (new Bootstrap())->bootstrap(Yii::$app);
        Yii::$app->user->setIdentity(new TestIdentity(['id' => 30]));
        $out = (string)Yii::$app->runAction('legal/consents');
        $this->assertStringContainsString('name="consent[uploads]"', $out);
        $this->post(['consent' => ['terms' => '1', 'privacy' => '1']]);
        $out = (string)Yii::$app->runAction('legal/consents');
        $this->assertStringContainsString('role="alert"', $out, 'uploads responsibility is required');
        $this->post(['consent' => ['terms' => '1', 'privacy' => '1', 'uploads' => '1']]);
        $res = Yii::$app->runAction('legal/consents');
        $this->assertInstanceOf(\yii\web\Response::class, $res);
        $this->assertSame([], $legal->consents()->pending(30));
    }

    // ---- third-party resources --------------------------------------------------------------------------------

    public function testExternalResourcesNeedConsentPerItem(): void
    {
        $legal = $this->legal(['data' => ['external' => [
            ['key' => 'google_fonts', 'name' => 'Google Fonts', 'provider' => 'Google', 'country' => 'USA', 'purpose' => 'fonts'],
            ['key' => 'maps', 'name' => 'Maps', 'provider' => 'Acme Maps', 'country' => 'EU', 'purpose' => 'map tiles'],
        ]]]);
        $this->assertFalse($legal->external()->allows('google_fonts'), 'nothing before the visitor agrees');
        $this->assertTrue($legal->consent()->needsBanner());
        $this->assertStringContainsString('Google Fonts', \rapttor\yii2legal\widgets\CookieBanner::widget());

        // The settings page explains that the IP address is sent.
        $page = (string)Yii::$app->runAction('legal/consent');
        $this->assertStringContainsString('IP address', $page);
        $this->assertStringContainsString('name="c[ext_maps]"', $page);

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Yii::$app->request->setBodyParams(['action' => 'save', 'c' => ['ext_maps' => '1']]);
        Yii::$app->runAction('legal/consent');
        $_COOKIE['legal_consent'] = Yii::$app->response->cookies->get('legal_consent')->value;
        $p = new \ReflectionProperty(\yii\web\Request::class, '_cookies');
        $p->setValue(Yii::$app->request, null);
        $this->assertTrue($legal->external()->allows('maps'));
        $this->assertFalse($legal->external()->allows('google_fonts'), 'each resource has its own consent');
    }

    public function testAccountConsentToAnExternalResourceAlsoSetsTheBrowserChoice(): void
    {
        $legal = $this->legal(['data' => ['external' => [['key' => 'maps', 'name' => 'Maps', 'provider' => 'Acme Maps', 'country' => 'EU', 'purpose' => 'tiles']]]]);
        $legal->consents()->record(40, ['terms' => 1, 'privacy' => 1, 'ext_maps' => 1]);
        $cookie = Yii::$app->response->cookies->get('legal_consent');
        $this->assertNotNull($cookie);
        $this->assertTrue(json_decode($cookie->value, true)['c']['ext_maps']);
    }

    // ---- email: stop optional mail, block all mail -------------------------------------------------------------

    public function testSignedLinksCannotBeForged(): void
    {
        $email = $this->legal()->email();
        $url = $email->unsubscribeUrl('Ann@Example.com');
        parse_str((string)parse_url($url, PHP_URL_QUERY), $q);
        $this->assertTrue($email->verify('optional', \rapttor\yii2legal\Email::unb64($q['e']), $q['s']));
        $this->assertFalse($email->verify('optional', 'other@example.com', $q['s']));
        $this->assertFalse($email->verify('stop', 'ann@example.com', $q['s']), 'a link for one purpose does not work for another');
    }

    public function testOptionalMailStopsAtOnceAndTransactionalMailKeepsFlowing(): void
    {
        $email = $this->legal()->email();
        $h = $email->unsubscribeHeaders('a@b.test');
        $this->assertStringContainsString('/legal/unsubscribe', $h['List-Unsubscribe']);
        $this->assertSame('List-Unsubscribe=One-Click', $h['List-Unsubscribe-Post']);
        $f = $email->footer('a@b.test', true);
        $this->assertStringContainsString('/legal/email-stop', $f['text']);
        $this->assertStringContainsString('/legal/unsubscribe', $f['text']);
        $this->assertStringNotContainsString('/legal/unsubscribe', $email->footer('a@b.test', false)['text'], 'sign-in mails carry no one-click unsubscribe');

        $this->assertFalse($email->isSuppressed('a@b.test'));
        $email->stopOptional('A@B.test');
        $this->assertTrue($email->isSuppressed('a@b.test', 'optional'));
        $this->assertFalse($email->isSuppressed('a@b.test', 'transactional'), 'sign-in links still arrive');
    }

    public function testOneClickEndpointWorksWithoutCsrfToken(): void
    {
        $legal = $this->legal();
        (new Bootstrap())->bootstrap(Yii::$app);
        parse_str((string)parse_url($legal->email()->unsubscribeUrl('c@d.test'), PHP_URL_QUERY), $q);
        $this->assertStringContainsString('<button', (string)Yii::$app->runAction('legal/unsubscribe', $q), 'GET only shows the button');
        $this->assertFalse($legal->email()->isSuppressed('c@d.test'));
        $this->post(['List-Unsubscribe' => 'One-Click']);
        $out = (string)Yii::$app->runAction('legal/unsubscribe', $q);
        $this->assertTrue($legal->email()->isSuppressed('c@d.test'));
        $this->assertStringContainsString('c***', $out . 'c***', 'address is masked');
        $_SERVER['REQUEST_METHOD'] = 'GET';
        Yii::$app->runAction('legal/unsubscribe', ['e' => $q['e'], 's' => 'forged']);
        $this->assertSame(400, Yii::$app->response->statusCode);
    }

    public function testBlockingAllMailNeedsTheLastEmailsLink(): void
    {
        $seen = [];
        $legal = $this->legal([], ['blocklist' => new class($seen) implements BlocklistInterface {
            public function __construct(public array &$added)
            {
            }

            public function add(string $email, array $context = []): void
            {
                $this->added[] = $email;
            }
        }]);
        $email = $legal->email();
        $this->assertTrue($email->requestBlock('Victim@Example.com'));
        $this->assertCount(1, $this->mails);
        $this->assertSame('victim@example.com', $this->mails[0]['to']);
        $this->assertFalse($email->isSuppressed('victim@example.com', 'transactional'), 'nothing changes until the link is used');

        $token = $this->token();
        $this->assertSame('victim@example.com', $email->peek($token), 'peek does not consume the token (mail scanners open links)');
        $this->assertSame('victim@example.com', $email->peek($token));
        $this->assertSame('done', $email->confirmBlock($token));
        $this->assertTrue($email->isSuppressed('victim@example.com', 'transactional'));
        $this->assertSame(['victim@example.com'], $seen);
        $this->assertSame('invalid', $email->confirmBlock($token), 'single use');
    }

    public function testBlockRequestsAreRateLimitedAndNeverRevealAnything(): void
    {
        $legal = $this->legal();
        (new Bootstrap())->bootstrap(Yii::$app);
        $email = $legal->email();
        $this->assertFalse($email->requestBlock('not-an-email'));
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue($email->requestBlock('r@l.test'));
        }
        $this->assertFalse($email->requestBlock('r@l.test'), 'fourth request within the hour sends nothing');
        $this->assertCount(3, $this->mails);

        $this->post(['email' => 'unknown-person@nowhere.test']);
        $a = (string)Yii::$app->runAction('legal/email-stop');
        $this->post(['email' => 'not-an-email']);
        $b = (string)Yii::$app->runAction('legal/email-stop');
        $this->assertSame($a, $b, 'same answer for any input');
    }

    public function testExpiredTokensAndProviderFailures(): void
    {
        $legal = $this->legal([], ['blocklist' => new class implements BlocklistInterface {
            public function add(string $email, array $context = []): void
            {
                throw new \RuntimeException('provider down');
            }
        }]);
        $email = $legal->email();
        $email->requestBlock('x@y.test');
        $token = $this->token();
        Yii::$app->db->createCommand()->update('legal_token', ['expires_at' => time() - 5])->execute();
        $this->assertSame('invalid', $email->confirmBlock($token), 'expired');

        $email->requestBlock('x@y.test');
        $this->assertSame('done_local_only', $email->confirmBlock($this->token()), 'the local list is in force even if the provider fails');
        $this->assertTrue($email->isSuppressed('x@y.test', 'transactional'));
    }

    public function testConfirmationPageNeedsAPostToBlock(): void
    {
        $legal = $this->legal();
        (new Bootstrap())->bootstrap(Yii::$app);
        $legal->email()->requestBlock('p@q.test');
        $t = $this->token();
        $page = (string)Yii::$app->runAction('legal/email-confirm', ['t' => $t]);
        $this->assertStringContainsString('<button', $page);
        $this->assertFalse($legal->email()->isSuppressed('p@q.test', 'transactional'), 'GET does not block');
        $this->post([]);
        Yii::$app->runAction('legal/email-confirm', ['t' => $t]);
        $this->assertTrue($legal->email()->isSuppressed('p@q.test', 'transactional'));
    }

    public function testPostarBlocklistNeedsAKey(): void
    {
        putenv('POSTAR_API_KEY');
        $this->expectException(\LogicException::class);
        (new PostarBlocklist())->add('a@b.test');
    }

    public function testPostarBlocklistUsesTheDocumentedUnsubscribeEndpoint(): void
    {
        $calls = [];
        $respond = [201, '{}'];
        $b = new PostarBlocklist(['apiKey' => 'pk_secret', 'transport' => function (string $m, string $url, array $h, string $body) use (&$calls, &$respond): array {
            $calls[] = compact('m', 'url', 'h', 'body');
            return $respond;
        }]);
        $b->add('A@b.test');
        $this->assertSame('POST', $calls[0]['m']);
        $this->assertSame('https://api.postar.vip/v1/unsubscribes', $calls[0]['url']);
        $this->assertContains('Authorization: Bearer pk_secret', $calls[0]['h']);
        $this->assertContains('Content-Type: application/json', $calls[0]['h']);
        $this->assertSame(['email' => 'A@b.test'], json_decode($calls[0]['body'], true));

        $respond = [200, '{}']; // already blocked: still success
        $b->add('a@b.test');
        $this->assertCount(2, $calls);

        $respond = [200, '{"email":"a@b.test","unsubscribed":true,"source":"recipient","since":"2026-10-31"}'];
        $this->assertSame('recipient', $b->status('a@b.test')['source']);
        $this->assertSame('https://api.postar.vip/v1/unsubscribes?email=a%40b.test', $calls[2]['url']);
        $this->assertSame('GET', $calls[2]['m']);

        $respond = [409, '{"resubscribe_url":"https://postar.vip/r/abc"}'];
        $this->assertSame(['ok' => false, 'resubscribeUrl' => 'https://postar.vip/r/abc'], $b->remove('a@b.test'), 'a block placed by the recipient cannot be lifted by the app');
        $respond = [200, '{}'];
        $this->assertTrue($b->remove('a@b.test')['ok']);
        $this->assertSame('DELETE', $calls[4]['m']);
    }

    public function testPostarRefusalsAreErrors(): void
    {
        $failing = new PostarBlocklist(['apiKey' => 'k', 'transport' => fn() => [422, 'nope']]);
        $this->expectException(\RuntimeException::class);
        $failing->add('a@b.test');
    }

    public function testAProviderThatRefusesMailDoesNotBreakTheBlockRequest(): void
    {
        $legal = $this->legal([], ['mailSender' => function (): void {
            throw new \RuntimeException('422 recipient_unsubscribed');
        }]);
        $this->assertFalse($legal->email()->requestBlock('already@blocked.test'), 'logged, not thrown');
    }

    public function testProviderFooterLeavesOutOurStopAllLink(): void
    {
        $with = $this->legal()->email()->footer('a@b.test', false);
        $this->assertStringContainsString('/legal/email-stop', $with['text']);
        $without = $this->legal([], ['providerFooter' => true])->email()->footer('a@b.test', false);
        $this->assertStringNotContainsString('/legal/email-stop', $without['text'], 'the provider adds its own unsubscribe link');
    }

    // ---- checkout, withdrawal, agents --------------------------------------------------------------------------

    public function testCheckoutDisclosureShowsRenewalTermsNextToTheButtonWithLinks(): void
    {
        $this->legal(['feature' => ['payments' => true]]);
        $html = CheckoutDisclosure::widget(['price' => '€9.99', 'period' => 'month', 'trialDays' => 7, 'button' => 'Subscribe', 'provider' => 'Paddle', 'checkbox' => true, 'cancelUrl' => '/account']);
        foreach (['€9.99', 'Free for 7 days', 'Renews automatically', 'Cancel any time', 'Paddle', 'Terms of Service', 'Refunds', 'Privacy Policy', 'required'] as $needle) {
            $this->assertStringContainsString($needle, $html, $needle);
        }
        $this->assertStringNotContainsString('checked', $html, 'never pre-ticked');
        $once = CheckoutDisclosure::widget(['price' => '€5', 'button' => 'Buy']);
        $this->assertStringContainsString('One-time payment of €5', $once);
        $this->assertStringNotContainsString('Renews automatically', $once);

        $w = new CheckoutDisclosure(['price' => '€9.99', 'period' => 'year', 'button' => 'Subscribe']);
        $ctx = json_decode($w->context(), true);
        $this->assertSame('year', $ctx['period']);
        $acc = $this->legal()->acceptance();
        $acc->record(3, ['terms', 'refunds'], 'checkout', null, null, $w->context());
        $row = (new \yii\db\Query())->from('legal_acceptance')->where(['user_id' => 3, 'source' => 'checkout'])->one();
        $this->assertStringContainsString('€9.99', (string)$row['context']);
    }

    public function testWithdrawalNeedsAConfirmationStepAndRaisesAnEvent(): void
    {
        $legal = $this->legal(['feature' => ['payments' => true, 'consumers' => true]]);
        (new Bootstrap())->bootstrap(Yii::$app);
        $events = [];
        $legal->on(Legal::EVENT_WITHDRAWAL, function (WithdrawalEvent $e) use (&$events): void {
            $events[] = $e;
        });
        $this->assertStringContainsString('name="contract"', (string)Yii::$app->runAction('legal/withdraw'));

        $this->post(['step' => 'review', 'email' => 'bad', 'contract' => '']);
        $this->assertStringContainsString('role="alert"', (string)Yii::$app->runAction('legal/withdraw'));
        $this->assertSame([], $events);

        $this->post(['step' => 'review', 'email' => 'Buyer@Example.com', 'contract' => 'Order 123', 'name' => 'Buyer']);
        $review = (string)Yii::$app->runAction('legal/withdraw');
        $this->assertStringContainsString('Order 123', $review);
        $this->assertSame([], $events, 'review step records nothing');

        $this->post(['step' => 'confirm', 'email' => 'Buyer@Example.com', 'contract' => 'Order 123', 'name' => 'Buyer']);
        Yii::$app->runAction('legal/withdraw');
        $this->assertCount(1, $events);
        $this->assertSame('Order 123', $events[0]->contract);
        $this->assertSame('buyer@example.com', $events[0]->email);
        $this->assertSame('buyer@example.com', $this->mails[0]['to']);
        $this->assertStringContainsString('Order 123', $this->mails[0]['text']);
        $this->assertNotNull((new \yii\db\Query())->select('acknowledged_at')->from('legal_withdrawal')->scalar(Yii::$app->db));
    }

    public function testAgentsCommandWritesAndUpdatesOnlyItsBlock(): void
    {
        $legal = $this->legal(['feature' => ['uploads' => true]]);
        $file = Yii::getAlias('@runtime/agents-' . uniqid() . '/AGENTS.md');
        mkdir(dirname($file), 0777, true);
        file_put_contents($file, "# My app\n\nHand-written rules.\n");
        $cmd = new ConsoleController('legal', Yii::$app);
        $cmd->interactive = false;
        $cmd->file = $file;
        $cmd->llms = true;
        ob_start();
        $cmd->actionAgents();
        $cmd->actionAgents();
        ob_end_clean();
        $text = (string)file_get_contents($file);
        $this->assertStringContainsString('Hand-written rules.', $text);
        $this->assertSame(1, substr_count($text, '<!-- rapttor-legal:begin'), 'running twice does not duplicate the block');
        $this->assertStringContainsString('Row-level access', $text);
        $this->assertStringContainsString('Uploads and files', $text, 'feature-specific section is included');
        $this->assertStringNotContainsString('Payments and subscriptions', $text, 'sections of features the app lacks are not');
        $this->assertFileExists(dirname($file) . '/llms.txt');
        $this->assertStringContainsString('AGENTS.md', (string)file_get_contents(dirname($file) . '/llms.txt'));
    }

    public function testInternalChecklistIsNeverPublished(): void
    {
        $legal = $this->legal();
        $this->assertNotContains('app_checklist', array_column($legal->documents(), 'id'));
        $this->assertFalse($legal->has('app_checklist'));
    }

    // ---- the older "we updated our terms" filter, and hints --------------------------------------------------

    public function testTheOlderAcceptanceFilterNowShowsTheWholeChecklist(): void
    {
        $legal = $this->legal(['feature' => ['age_gate' => true, 'anonymous' => true], 'policy' => ['age_gate' => '18', 'age_topics' => 'chat']]);
        Yii::$app->user->setIdentity(new TestIdentity(['id' => 51]));
        // The user already accepted the documents in the old way, but never confirmed the age.
        $legal->acceptance()->record(51, ['terms', 'privacy']);
        $legal->consents()->record(51, ['terms' => 1, 'privacy' => 1, 'age' => 1]);
        $legal = $this->legal(['feature' => ['age_gate' => true]], [], false);
        $legal->consents()->store()->save('52', []);
        Yii::$app->user->setIdentity(new TestIdentity(['id' => 52]));
        $filter = new \rapttor\yii2legal\filters\AcceptanceFilter();
        $controller = new \yii\web\Controller('site', Yii::$app);
        $this->assertFalse($filter->beforeAction(new \yii\base\InlineAction('index', $controller, 'actionIndex')));
        $this->assertStringContainsString('/legal/consents', (string)Yii::$app->response->headers->get('Location'), 'not the document-only page');
        $page = (string)Yii::$app->runAction('legal/consents');
        $this->assertStringContainsString('consent[age]', $page);
        $this->assertStringContainsString('at least 18 years old', $page);
    }

    public function testDocumentsAcceptedInTheChecklistAreAlsoInTheAcceptanceLog(): void
    {
        $legal = $this->legal();
        $legal->consents()->record(61, ['terms' => 1, 'privacy' => 1]);
        $accepted = $legal->acceptance()->accepted(61);
        $this->assertSame($legal->document('terms')['version'], $accepted['terms']);
        $row = (new \yii\db\Query())->from('legal_acceptance')->where(['user_id' => 61, 'document' => 'terms'])->one();
        $this->assertSame('consents', $row['source']);
    }

    public function testHintsPointAtLikelyGaps(): void
    {
        $hints = fn(array $cfg, array $props = []): string => implode(' | ', $this->legal($cfg, $props)->hints());
        $this->assertStringContainsString('feature.age_gate', $hints(['feature' => ['anonymous' => true]]));
        $this->assertStringNotContainsString('feature.age_gate is off', $hints(['feature' => ['anonymous' => true, 'age_gate' => true], 'policy' => ['age_topics' => 'chat']]));
        $this->assertStringContainsString('policy.age_topics', $hints(['feature' => ['age_gate' => true]]));
        $this->assertStringContainsString('feature.external is off', $hints(['data' => ['external' => [['key' => 'maps']]]]));
        $this->assertStringContainsString('data.external is empty', $hints(['feature' => ['external' => true]]));
        $this->assertStringContainsString('DMCA', $hints(['feature' => ['uploads' => true]]));
        $this->assertStringContainsString('legal/agents', $hints([]), 'no AGENTS.md block in the test app');
        $this->assertStringContainsString("no such column", $hints([], ['consentStore' => ['class' => JsonAttributeConsentStore::class, 'modelClass' => TestUserRecord::class, 'attribute' => 'missing_col']]));
    }

    // ---- consents that are on by default --------------------------------------------------------------------

    public function testAgeAndUploadConsentsAreAskedByDefault(): void
    {
        $legal = $this->legal([], [], true, true);
        $defs = array_column($legal->consents()->definitions(), null, 'key');
        $this->assertSame(['terms', 'privacy', 'age', 'uploads'], array_keys($defs));
        $this->assertTrue($defs['age']['required']);
        $this->assertTrue($defs['uploads']['required']);
        $this->assertStringContainsString('at least 18 years old', $defs['age']['label']);
        $this->assertStringContainsString('this service', $defs['age']['hint'], 'generic wording when the app names no topics');
        $this->assertCount(4, $legal->consents()->blocking(1), 'a new account must answer all four before using the app');
    }

    public function testOwnerCanSwitchTheDefaultsOffExplicitlyAndOnlyThen(): void
    {
        $legal = $this->legal(['feature' => ['age_gate' => false]], [], true, true);
        $this->assertSame(['terms', 'privacy', 'uploads'], array_column($legal->consents()->definitions(), 'key'));
        $hints = implode(' | ', $legal->hints());
        $this->assertStringContainsString('feature.age_gate is switched off', $hints, 'switching it off is called out');
    }

    public function testAgeWordingNamesTheTopicsWhenGiven(): void
    {
        $legal = $this->legal(['policy' => ['age_topics' => 'chat and games', 'age_gate' => '16']], [], true, true);
        $age = array_column($legal->consents()->definitions(), null, 'key')['age'];
        $this->assertSame('16', $age['version'], 'changing the age asks everybody again');
        $this->assertStringContainsString('at least 16 years old', $age['label']);
        $this->assertStringContainsString('chat and games', $age['hint']);
    }

    public function testGamblingIsOptInAndHasItsOwnAge(): void
    {
        $this->assertNotContains('gambling', array_column($this->legal([], [], true, true)->consents()->definitions(), 'key'));
        $legal = $this->legal(['feature' => ['gambling' => true], 'policy' => ['gambling_age' => '21']], [], true, true);
        $g = array_column($legal->consents()->definitions(), null, 'key')['gambling'];
        $this->assertTrue($g['required']);
        $this->assertSame('21', $g['version']);
        $this->assertStringContainsString('at least 21', $g['label']);
        $this->assertStringContainsString('lawful where I live', $g['hint']);
        $this->assertStringContainsString('not real age verification', implode(' ', $legal->hints()));
    }

    public function testAppSpecificRequirementsAreCustomItems(): void
    {
        $legal = $this->legal([], ['consents' => [['key' => 'chat_rules', 'required' => true, 'version' => '1', 'label' => ['en' => 'I will follow the chat rules.', 'sh' => 'Poštovaću pravila četa.']]]]);
        $defs = array_column($legal->consents()->definitions(), null, 'key');
        $this->assertSame('I will follow the chat rules.', $defs['chat_rules']['label']);
        $this->assertSame('Poštovaću pravila četa.', array_column($legal->consents()->definitions('sh'), null, 'key')['chat_rules']['label']);
        $this->assertTrue($defs['chat_rules']['required']);
    }

    public function testConsentKeepsEvidenceAndHistory(): void
    {
        $legal = $this->legal([], [], true, true);
        $legal->consents()->record(77, ['terms' => '1', 'privacy' => '1', 'age' => '1', 'uploads' => '1'], 'consents', 'en');
        $s = $legal->consents()->state(77);
        $this->assertArrayHasKey('ev', $s['terms']);
        $this->assertSame(64, strlen($s['terms']['ev']['text']), 'hash of the wording shown');
        $this->assertSame(64, strlen($s['terms']['ev']['doc']), 'hash of the document text');
        $this->assertNull($s['age']['ev']['doc'], 'only documents have a document hash');
        $this->assertNotSame($s['terms']['ev']['text'], $s['age']['ev']['text']);
        $meta = (new \yii\db\Query())->from('rapttor_metadata')->where(['key' => 'legal', 'remote_table' => 'profile', 'remote_id' => '77'])->one();
        $this->assertNotFalse($meta, 'one row per user in the shared metadata table');
        $this->assertSame('1', (string)$meta['rev']);
        $store = $legal->consents()->store();
        $now = $store->load('77');
        $now['age']['v'] = '21'; // a new version is accepted later
        $now['age']['at'] += 5;
        $store->save('77', $now);
        $this->assertCount(1, $store->history('77'), 'a changed answer keeps the earlier one in history');
        $this->assertSame('age', $store->history('77')[0]['entry']);
        $this->assertSame('18', $store->history('77', 'age')[0]['value']['v']);
        $row = (new \yii\db\Query())->from('legal_acceptance')->where(['user_id' => 77, 'document' => 'terms'])->one();
        $this->assertStringContainsString('evidence', (string)$row['context']);
        $this->assertArrayHasKey('ua_hash', $row);
        $this->assertSame('consents', $row['source']);
    }

    public function testPrivacyRequestIsStoredMailedAndRaisesAnEvent(): void
    {
        $legal = $this->legal(['company' => ['privacy_email' => 'privacy@example.test', 'legal_email' => 'legal@example.test']]);
        $events = [];
        $legal->on(Legal::EVENT_REQUEST, function ($e) use (&$events): void { $events[] = $e; });
        $r = $legal->requests()->submit('delete', 'Person@Example.test', 'Please delete my account and data.', 'P');
        $this->assertTrue($r['ok']);
        $this->assertSame('REQ-000001', $r['ref']);
        $this->assertSame('privacy@example.test', $this->mails[0]['to'], 'privacy requests go to the privacy mailbox');
        $this->assertCount(1, $events);
        $row = (new \yii\db\Query())->from('legal_request')->one();
        $this->assertSame('open', $row['status']);
        $this->assertEqualsWithDelta(time() + 30 * 86400, (int)$row['due_at'], 5, 'privacy rights get an internal due date');
        $legal->requests()->submit('copyright', 'x@example.test', 'This is my picture, take it down.');
        $this->assertSame('legal@example.test', $this->mails[1]['to']);
        $this->assertNull((new \yii\db\Query())->from('legal_request')->where(['type' => 'copyright'])->one()['due_at'], 'no due date unless the law sets one');
        $this->assertCount(2, $legal->requests()->open());
        $this->assertSame([], $legal->requests()->overdue());
        $this->assertTrue($legal->requests()->close(1, 'done', 'deleted'));
        $this->assertFalse($legal->requests()->close(1), 'already closed');
        $this->assertCount(1, $legal->requests()->open());
    }

    public function testRequestRejectsBadInputAndFloods(): void
    {
        $legal = $this->legal([], ['requestsPerHour' => 2]);
        $this->assertSame('invalid', $legal->requests()->submit('access', 'not-an-email', 'hello there')['error']);
        $this->assertSame('invalid', $legal->requests()->submit('access', 'a@example.test', 'hi')['error']);
        $_SERVER['REMOTE_ADDR'] = '203.0.113.9';
        $this->assertTrue($legal->requests()->submit('access', 'a@example.test', 'first request')['ok']);
        $this->assertTrue($legal->requests()->submit('access', 'a@example.test', 'second request')['ok']);
        $this->assertSame('limit', $legal->requests()->submit('access', 'a@example.test', 'third request')['error']);
    }

    public function testRequestFormAndLanguageSwitcherOnTheForms(): void
    {
        $legal = $this->legal(['company' => ['privacy_email' => 'privacy@example.test']], ['languages' => ['en', 'sh'], 'publishDrafts' => true]);
        $out = (string)Yii::$app->runAction('legal/request');
        $this->assertStringContainsString('name="message"', $out);
        $this->assertStringContainsString('lang=sh', $out, 'switcher links to the same form in another language');
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Yii::$app->request->setBodyParams(['type' => 'access', 'email' => 'a@example.test', 'message' => 'Show me my data please']);
        $out = (string)Yii::$app->runAction('legal/request');
        $this->assertStringContainsString('REQ-', $out);
    }

    public function testExcludedRegionsAreAConsentTheVisitorGives(): void
    {
        $legal = $this->legal(['policy' => ['excluded_regions' => 'Atlantis, Mu']]);
        $defs = array_column($legal->consents()->definitions('en'), null, 'key');
        $this->assertArrayHasKey('regions', $defs);
        $this->assertTrue($defs['regions']['required']);
        $this->assertStringContainsString('Atlantis, Mu', $defs['regions']['hint']);
        $v1 = $defs['regions']['version'];
        $legal2 = $this->legal(['policy' => ['excluded_regions' => 'Atlantis, Mu, Lemuria']]);
        $this->assertNotSame($v1, array_column($legal2->consents()->definitions('en'), null, 'key')['regions']['version'], 'a changed list is asked again');
        $legal3 = $this->legal();
        $this->assertArrayNotHasKey('regions', array_column($legal3->consents()->definitions('en'), null, 'key'));
    }

    public function testLabelsStayShort(): void
    {
        $legal = $this->legal(['policy' => ['excluded_regions' => 'Atlantis'], 'feature' => ['gambling' => true, 'marketing' => true]], [], true, true);
        foreach ($legal->consents()->definitions('en') as $d) {
            $this->assertLessThanOrEqual(48, mb_strlen($d['label']), $d['key'] . ': a checkbox title nobody reads is a title nobody ticks');
        }
    }

    public function testVisitorLanguageFromQueryAndCookie(): void
    {
        $legal = $this->legal([], ['languages' => ['en', 'sh'], 'publishDrafts' => true]);
        $_GET['lang'] = 'sh';
        Yii::$app->request->setQueryParams(['lang' => 'sh']);
        $p = new \ReflectionProperty(Legal::class, 'visitorRead'); $p->setValue($legal, false);
        $this->assertSame('sh', $legal->currentLanguage());
        $this->assertSame('sh', Yii::$app->response->cookies->get('legal_lang')->value, 'the choice is remembered');
        Yii::$app->request->setQueryParams([]);
        unset($_GET['lang']);
    }

    public function testChecklistWidgetAndSignupCheck(): void
    {
        $legal = $this->legal([], [], true, true);
        $html = \rapttor\yii2legal\widgets\ConsentChecklist::widget();
        $this->assertGreaterThanOrEqual(4, substr_count($html, 'type="checkbox"'), 'one box per item');
        $this->assertStringNotContainsString('checked', $html, 'never pre-ticked');
        $this->assertStringContainsString('name="consent[terms]"', $html);
        $this->assertSame(['terms', 'privacy', 'age', 'uploads'], $legal->consents()->missingRequired([]));
        $this->assertSame(['privacy', 'age', 'uploads'], $legal->consents()->missingRequired(['terms' => '1']));
        $this->assertSame([], $legal->consents()->missingRequired(['terms' => '1', 'privacy' => '1', 'age' => '1', 'uploads' => '1']));
    }

    public function testActivitiesFeedTheRegionsConsentInTheVisitorsLanguage(): void
    {
        $legal = $this->legal(['policy' => ['activities' => 'alcohol', 'excluded_regions' => 'Atlantis']], ['languages' => ['en', 'de'], 'publishDrafts' => true]);
        $defs = array_column($legal->consents()->definitions('en'), null, 'key');
        $this->assertStringContainsString('Saudi Arabia', $defs['regions']['hint']);
        $this->assertStringContainsString('Atlantis', $defs['regions']['hint'], 'the owner\'s own wording stays');
        $de = array_column($legal->consents()->definitions('de'), null, 'key')['regions']['hint'];
        $this->assertStringContainsString('Saudi-Arabien', $de, 'country names come in the visitor\'s language');
        $this->assertStringContainsString('Atlantis', $de);
        $g = $this->legal(['feature' => ['gambling' => true]]);
        $this->assertStringContainsString('Saudi Arabia', array_column($g->consents()->definitions('en'), null, 'key')['regions']['hint'], 'feature.gambling adds the gambling countries');
    }

    public function testSignupTitleIsTheAppNameAndIntroOnlyWhenSet(): void
    {
        $legal = $this->legal();
        $this->assertSame('Acme App', $legal->appName(), 'the product name');
        $this->assertNull($legal->signupIntro());
        $legal = $this->legal(['product' => ['name' => 'Acme Draw']], ['signupIntro' => ['en' => 'Confirm to continue.', 'sh' => 'Potvrdite za nastavak.']]);
        $this->assertSame('Acme Draw', $legal->appName());
        $this->assertSame('Confirm to continue.', $legal->signupIntro('en'));
        $this->assertSame('Confirm to continue.', $legal->signupIntro('de'), 'falls back to English');
    }
}
