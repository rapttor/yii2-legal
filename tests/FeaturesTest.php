<?php

declare(strict_types=1);

namespace rapttor\yii2legal\tests;

use PHPUnit\Framework\TestCase;
use rapttor\yii2legal\Acceptance;
use rapttor\yii2legal\Bootstrap;
use rapttor\yii2legal\Legal;
use Yii;

final class FeaturesTest extends TestCase
{
    private const BASE = [
        'company' => ['name' => 'Acme d.o.o.', 'address' => '1 Main St, Town, Land', 'country' => 'Land', 'email' => 'hi@acme.test', 'website' => 'https://acme.test'],
    ];

    private function legal(array $extra = [], array $props = []): Legal
    {
        $legal = new Legal(array_replace([
            'config' => array_replace_recursive(self::BASE, $extra), 'cache' => null,
            'archivePath' => '@runtime/legal-' . uniqid(), 'acceptanceTable' => 'legal_acceptance',
        ], $props));
        Yii::$app->set('legal', $legal);
        return $legal;
    }

    protected function setUp(): void
    {
        Acceptance::createTable(Yii::$app->db, 'legal_acceptance');
        Yii::$app->db->createCommand()->delete('legal_acceptance')->execute();
        Yii::$app->request->setBodyParams([]);
        Yii::$app->response->cookies->removeAll();
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->requestCookies([]);
        Yii::$app->request->headers->remove('Sec-GPC');
    }

    protected function tearDown(): void
    {
        $_SERVER['REQUEST_METHOD'] = 'GET';
        $this->requestCookies([]);
    }

    /** Request cookies are read-only in Yii: load them from $_COOKIE again, as the next request would. @param array<string,string> $cookies */
    private function requestCookies(array $cookies): void
    {
        $_COOKIE = $cookies;
        $p = new \ReflectionProperty(\yii\web\Request::class, '_cookies');
        $p->setValue(Yii::$app->request, null);
    }

    // ---- acceptance -------------------------------------------------------------------------------------------

    public function testRecordsAcceptanceAndKnowsWhatIsPending(): void
    {
        $legal = $this->legal();
        $acc = $legal->acceptance();
        $this->assertCount(2, $acc->pending(7), 'terms and privacy are pending for a new user');
        $this->assertSame(2, $acc->record(7, null, 'signup'));
        $this->assertSame([], $acc->pending(7));
        $this->assertSame('1.0.0', $acc->accepted(7)['terms']);
        $row = (new \yii\db\Query())->from('legal_acceptance')->where(['user_id' => 7, 'document' => 'terms'])->one();
        $this->assertSame('signup', $row['source']);
    }

    public function testOnlyMajorOrMinorChangesNeedReacceptance(): void
    {
        $this->assertTrue(Acceptance::newer('1.1.0', '1.0.9'));
        $this->assertTrue(Acceptance::newer('2.0.0', '1.9.9'));
        $this->assertFalse(Acceptance::newer('1.0.5', '1.0.0'), 'a wording fix does not need new consent');
        $this->assertFalse(Acceptance::newer('1.0.0', '1.0.0'));
        $this->assertFalse(Acceptance::newer('1.0.0', '1.2.0'));

        $legal = $this->legal();
        Yii::$app->db->createCommand()->insert('legal_acceptance', [
            'user_id' => 8, 'document' => 'terms', 'version' => '0.9.0', 'lang' => 'en', 'release' => 'x', 'source' => 't', 'accepted_at' => 1,
        ])->execute();
        $this->assertContains('terms', array_column($legal->acceptance()->pending(8, ['terms']), 'id'), 'older MINOR accepted: pending again');
    }

    public function testAcceptPageRecordsOnlyWithTheCheckbox(): void
    {
        $legal = $this->legal();
        (new Bootstrap())->bootstrap(Yii::$app);
        Yii::$app->user->setIdentity(new TestIdentity(['id' => 9]));

        $_SERVER['REQUEST_METHOD'] = 'POST';
        Yii::$app->request->setBodyParams(['return' => '/dashboard']);
        $out = (string)Yii::$app->runAction('legal/accept');
        $this->assertStringContainsString('role="alert"', $out, 'no checkbox: error and nothing recorded');
        $this->assertCount(2, $legal->acceptance()->pending(9));

        Yii::$app->request->setBodyParams(['legal_accept' => '1', 'return' => 'https://evil.test/']);
        $res = Yii::$app->runAction('legal/accept');
        $this->assertInstanceOf(\yii\web\Response::class, $res);
        $this->assertSame([], $legal->acceptance()->pending(9));
        $this->assertSame('/', $res->headers->get('Location'), 'external return URLs are ignored');
        $_SERVER['REQUEST_METHOD'] = 'GET';
    }

    // ---- cookie consent ---------------------------------------------------------------------------------------

    public function testBannerOnlyWhenNonEssentialCookiesAreUsed(): void
    {
        $this->assertFalse($this->legal()->consent()->needsBanner(), 'no analytics/ads: no banner');
        $legal = $this->legal(['feature' => ['analytics' => true, 'ads' => true]]);
        $this->assertSame(['analytics', 'advertising'], $legal->consent()->categories());
        $this->assertTrue($legal->consent()->needsBanner());
        $this->assertStringContainsString('Reject all', \rapttor\yii2legal\widgets\CookieBanner::widget());
        $this->assertStringContainsString('Accept all', \rapttor\yii2legal\widgets\CookieBanner::widget());
        $this->assertFalse($legal->consent()->allows('analytics'), 'nothing is allowed before the visitor acts');
    }

    public function testConsentChoicesAreStoredAndRead(): void
    {
        $legal = $this->legal(['feature' => ['analytics' => true, 'ads' => true]]);
        (new Bootstrap())->bootstrap(Yii::$app);
        $_SERVER['REQUEST_METHOD'] = 'POST';
        Yii::$app->request->setBodyParams(['action' => 'save', 'c' => ['analytics' => '1'], 'return' => '/x']);
        $res = Yii::$app->runAction('legal/consent');
        $cookie = Yii::$app->response->cookies->get('legal_consent');
        $this->assertNotNull($cookie);
        $this->assertSame('/x', $res->headers->get('Location'));
        $_SERVER['REQUEST_METHOD'] = 'GET';

        // The next request carries the cookie.
        $this->requestCookies(['legal_consent' => $cookie->value]);
        $this->assertTrue($legal->consent()->allows('analytics'));
        $this->assertFalse($legal->consent()->allows('advertising'));
        $this->assertFalse($legal->consent()->needsBanner(), 'decided: no banner');
    }

    public function testGlobalPrivacyControlRefusesEverythingAndHidesTheBanner(): void
    {
        $legal = $this->legal(['feature' => ['analytics' => true]]);
        Yii::$app->request->headers->set('Sec-GPC', '1');
        $this->requestCookies(['legal_consent' => json_encode(['v' => 1, 'c' => ['analytics' => true]])]);
        $this->assertTrue($legal->consent()->gpc());
        $this->assertFalse($legal->consent()->allows('analytics'), 'GPC wins over an earlier opt-in');
        $this->assertFalse($legal->consent()->needsBanner());
    }

    public function testUiStringsFollowTheLanguage(): void
    {
        $legal = $this->legal();
        $this->assertSame('Cookie settings', $legal->t('cookie.link', [], 'en'));
        $this->assertSame('Postavke kolačića', $legal->t('cookie.link', [], 'hr'));
        $this->assertSame('Подешавања колачића', $legal->t('cookie.link', [], 'sr-Cyrl'));
        $this->assertSame('Cookie settings', $legal->t('cookie.link', [], 'ja'), 'unknown language: English');
    }

    // ---- claims, approval, structured tables, TOC --------------------------------------------------------------

    public function testClaimsListsWhatToConfirm(): void
    {
        $legal = $this->legal(['feature' => ['analytics' => true]], ['confirmedClaims' => ['gpc-honoured']]);
        $byId = array_column($legal->claims(), null, 'id');
        $this->assertTrue($byId['gpc-honoured']['confirmed']);
        $this->assertFalse($byId['cookie-consent-before-tracking']['confirmed']);
        $this->assertArrayNotHasKey('game-end-of-service', $byId);

        $cmd = new \rapttor\yii2legal\console\LegalController('legal', Yii::$app);
        $cmd->interactive = false;
        ob_start();
        $code = $cmd->actionCheck();
        ob_end_clean();
        $this->assertSame(1, $code, 'unconfirmed claims fail the check');
        $cmd->skipClaims = true;
        ob_start();
        $this->assertSame(0, $cmd->actionCheck(), '--skipClaims lets the check pass');
        ob_end_clean();
        $cmd->skipClaims = false;

        $all = array_column($legal->claims(), 'id');
        $legal = $this->legal(['feature' => ['analytics' => true]], ['confirmedClaims' => $all]);
        ob_start();
        $this->assertSame(0, $cmd->actionCheck());
        ob_end_clean();
    }

    public function testApprovalGateKeepsServingTheApprovedVersion(): void
    {
        $legal = $this->legal([], ['requireApproval' => true]);
        $dir = Yii::getAlias($legal->archivePath);
        @mkdir($dir, 0777, true);
        // State: version 0.9.0 of the cookie policy was approved and archived; the bundle now has 1.0.0.
        file_put_contents("$dir/approved.json", json_encode(['release' => 'old', 'at' => 1, 'docs' => ['en/cookies' => '0.9.0']]));
        file_put_contents("$dir/20200101T000000-old-en-cookies-v0.9.0-deadbeef.md", 'OLD APPROVED TEXT');

        $this->assertSame('OLD APPROVED TEXT', $legal->markdown('cookies', 'en'));
        $changes = array_column($legal->approval()->changes(), null, 'id');
        $this->assertSame('0.9.0', $changes['cookies']['approved']);

        $legal->approval()->approveCurrent();
        $this->assertStringContainsString('Cookie Policy', $legal->markdown('cookies', 'en'), 'after approval the new text is served');
        $this->assertSame([], $legal->approval()->changes());
    }

    public function testStructuredDataBecomesTables(): void
    {
        $legal = $this->legal(['data' => ['subprocessors' => [['name' => 'Host', 'purpose' => 'Hosting', 'location' => 'EU']]]]);
        $html = $legal->html('subprocessors', 'en');
        $this->assertStringContainsString('<th>Name</th>', $html);
        $this->assertStringContainsString('<td>Host</td>', $html);
    }

    public function testLongDocumentsGetAContentsList(): void
    {
        $this->legal();
        (new Bootstrap())->bootstrap(Yii::$app);
        $out = (string)Yii::$app->runAction('legal/view', ['doc' => 'terms']);
        $this->assertStringContainsString('class="legal-toc"', $out);
        $this->assertStringContainsString('href="#termination"', $out);
    }
}
