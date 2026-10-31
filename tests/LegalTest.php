<?php

declare(strict_types=1);

namespace rapttor\yii2legal\tests;

use PHPUnit\Framework\TestCase;
use rapttor\yii2legal\Bootstrap;
use rapttor\yii2legal\Legal;
use Yii;
use yii\base\InvalidConfigException;
use yii\web\NotFoundHttpException;

final class LegalTest extends TestCase
{
    private const BASE = [
        'company' => ['name' => 'Acme d.o.o.', 'address' => '1 Main St, Town, Land', 'country' => 'Land', 'email' => 'hi@acme.test', 'website' => 'https://acme.test'],
    ];

    private function legal(array $extra = [], array $props = []): Legal
    {
        $legal = new Legal(array_replace(['config' => array_replace_recursive(self::BASE, $extra), 'cache' => null, 'archivePath' => '@runtime/legal-' . uniqid()], $props));
        Yii::$app->set('legal', $legal);
        return $legal;
    }

    public function testPublishesOnlyDocumentsForEnabledFeatures(): void
    {
        $ids = array_column($this->legal()->documents(), 'id');
        $this->assertContains('privacy', $ids);
        $this->assertNotContains('dpa', $ids);
        $this->assertNotContains('game_rules', $ids);

        $ids = array_column($this->legal(['feature' => ['game' => true, 'mobile' => true, 'payments' => true, 'processor_role' => true]])->documents(), 'id');
        foreach (['game_rules', 'app_eula', 'refunds', 'dpa'] as $id) {
            $this->assertContains($id, $ids);
        }
    }

    public function testSharedConfigAppearsInEveryDocument(): void
    {
        $legal = $this->legal(['feature' => ['payments' => true]]);
        foreach ($legal->documents() as $d) {
            $this->assertStringContainsString('Acme d.o.o.', $legal->markdown($d['id']), $d['id']);
        }
    }

    public function testHtmlHasAnchorsAndTables(): void
    {
        $html = $this->legal()->html('privacy');
        $this->assertStringContainsString('id="who-we-are"', $html);
        $this->assertStringContainsString('<table', $html);
        $this->assertStringContainsString('href="/legal/terms"', $html);
    }

    public function testSlugsAndUrls(): void
    {
        $legal = $this->legal();
        $this->assertSame('/legal/acceptable-use', $legal->url('acceptable_use'));
        $this->assertSame('acceptable_use', Legal::idFromSlug('acceptable-use'));
    }

    public function testConfigValuesCannotInjectMarkup(): void
    {
        $legal = $this->legal(['company' => ['name' => '<script>alert(1)</script>Evil', 'address' => '[x](javascript:alert(2))']]);
        $html = $legal->html('terms');
        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringContainsString('Evil', $html);
    }

    public function testMissingRequiredConfigThrowsWithoutArchive(): void
    {
        $legal = $this->legal(['company' => ['name' => '']]);
        $this->assertSame(['company.name'], $legal->missing());
        $this->expectException(InvalidConfigException::class);
        $legal->markdown('terms');
    }

    public function testMissingConfigServesLastArchivedRender(): void
    {
        $legal = $this->legal();
        $good = $legal->markdown('terms');
        $this->assertNotEmpty(glob(Yii::getAlias($legal->archivePath) . '/*-terms-*.md'));

        $broken = new Legal(['config' => ['company' => ['name' => '']] + self::BASE, 'cache' => null, 'archivePath' => $legal->archivePath]);
        $this->assertSame($good, $broken->markdown('terms'));
    }

    public function testArchiveWritesOncePerDistinctRender(): void
    {
        $legal = $this->legal();
        $legal->markdown('cookies');
        $legal->markdown('cookies');
        $this->assertCount(1, glob(Yii::getAlias($legal->archivePath) . '/*-cookies-*.md'));
        $this->legal(['company' => ['name' => 'Other Ltd']], ['archivePath' => $legal->archivePath])->markdown('cookies');
        $this->assertCount(2, glob(Yii::getAlias($legal->archivePath) . '/*-cookies-*.md'));
    }

    public function testControllerServesPublishedAndRejectsOthers(): void
    {
        $this->legal();
        (new Bootstrap())->bootstrap(Yii::$app);
        $out = Yii::$app->runAction('legal/view', ['doc' => 'privacy']);
        $this->assertStringContainsString('Privacy Policy', (string)$out);
        $this->expectException(NotFoundHttpException::class);
        Yii::$app->runAction('legal/view', ['doc' => 'dpa']);
    }

    public function testConsoleCheckAndExport(): void
    {
        $this->legal();
        $cmd = new \rapttor\yii2legal\console\LegalController('legal', Yii::$app);
        $cmd->interactive = false;
        $cmd->skipClaims = true;
        ob_start();
        $code = $cmd->actionCheck();
        $dir = Yii::getAlias('@runtime/export-' . uniqid());
        $code2 = $cmd->actionExport($dir, 'html');
        ob_end_clean();
        $this->assertSame(0, $code);
        $this->assertSame(0, $code2);
        $this->assertFileExists("$dir/en/acceptable-use.html");
        $this->assertStringContainsString('id="enforcement"', (string)file_get_contents("$dir/en/acceptable-use.html"));

        $this->legal(['company' => ['email' => '']]);
        ob_start();
        $code = $cmd->actionCheck();
        ob_end_clean();
        $this->assertSame(1, $code);
    }

    // ---- languages -------------------------------------------------------------------------------------------

    public function testOfferedLanguagesDefaultToAllAvailableAndHideDrafts(): void
    {
        $legal = $this->legal();
        $this->assertContains('en', $legal->offered());
        $this->assertNotContains('sh', $legal->offered(), 'draft translations are hidden by default');

        $legal = $this->legal([], ['publishDrafts' => true]);
        $this->assertContains('sh', $legal->offered());
    }

    public function testLanguagesOptionNarrowsAndOrdersTheSwitcher(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true, 'languages' => ['sh', 'en', 'xx', 'zz']]);
        $this->assertSame(['sh', 'en'], $legal->offered(), 'unknown or unavailable tags are ignored, order is kept');
        $this->assertSame('sh', $legal->resolve('sr_RS'));
        $this->assertSame('sh', $legal->resolve('hr'), 'Croatian, Bosnian, Montenegrin and Serbian tags are the one Balkan language');
        $this->assertSame('en', $legal->resolve('de'), 'a language the site does not offer falls back');
    }

    public function testDocumentsFallBackToEnglishPerDocument(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true]);
        $d = $legal->document('cookies', 'sh');
        $this->assertSame('sh', $d['lang']);
        $this->assertFalse($d['fallback']);
        $this->assertStringContainsString('kolačić', mb_strtolower($legal->html('cookies', 'sh')));
        $strict = $this->legal([], ['publishDrafts' => false]);
        $this->assertSame('en', $strict->document('cookies', 'ru')['lang'], 'a language without published texts shows the English document');
        $this->assertTrue($strict->document('cookies', 'ru')['fallback']);
    }

    public function testLanguageAwareUrlsAndRoutes(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true]);
        $this->assertSame('/legal/terms', $legal->url('terms', 'en'));
        $this->assertSame('/legal/sh/terms', $legal->url('terms', 'sh'));
        (new Bootstrap())->bootstrap(Yii::$app);
        $this->assertSame(['legal/view', ['lang' => 'sh', 'doc' => 'cookies']], Yii::$app->urlManager->parseRequest($this->request('legal/sh/cookies')));
        $this->assertSame(['legal/index', ['lang' => 'sh']], Yii::$app->urlManager->parseRequest($this->request('legal/sh')));
        $this->assertSame(['legal/view', ['doc' => 'privacy']], Yii::$app->urlManager->parseRequest($this->request('legal/privacy')));
    }

    public function testControllerServesLanguageAndRejectsUnofferedOnes(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true, 'languages' => ['en', 'sh']]);
        (new Bootstrap())->bootstrap(Yii::$app);
        $out = (string)Yii::$app->runAction('legal/view', ['doc' => 'cookies', 'lang' => 'sh']);
        $this->assertStringContainsString('lang="sh"', $out);
        $this->assertStringContainsString('Politika kolačića', $out);
        $this->assertSame('sh', Yii::$app->response->headers->get('Content-Language'));
        $this->assertArrayHasKey('hreflang-x-default', Yii::$app->view->linkTags);

        $out = (string)Yii::$app->runAction('legal/view', ['doc' => 'cookies', 'lang' => 'en']);
        $this->assertStringContainsString('Cookie Policy', $out);

        $this->expectException(NotFoundHttpException::class);
        Yii::$app->runAction('legal/view', ['doc' => 'cookies', 'lang' => 'de']);
    }

    public function testLanguageSwitcherListsOfferedAndHonoursOnly(): void
    {
        $this->legal([], ['publishDrafts' => true, 'languages' => ['en', 'sh']]);
        $all = \rapttor\yii2legal\widgets\LanguageSwitcher::widget(['doc' => 'cookies']);
        $this->assertStringContainsString('Balkan', $all);
        $this->assertStringContainsString('href="/legal/sh/cookies"', $all);
        $this->assertStringContainsString('aria-current', $all);

        $only = \rapttor\yii2legal\widgets\LanguageSwitcher::widget(['doc' => 'cookies', 'only' => ['sh', 'en']]);
        $this->assertStringNotContainsString('Deutsch', $only);
        $this->assertLessThan(strpos($only, 'English'), strpos($only, 'Balkan'), 'order follows "only"');

        $this->legal([], ['languages' => ['en']]);
        $this->assertSame('', \rapttor\yii2legal\widgets\LanguageSwitcher::widget(), 'a single language shows no switcher');
    }

    private function request(string $path): \yii\web\Request
    {
        $r = new \yii\web\Request(['url' => '/' . $path, 'pathInfo' => $path]);
        $r->setPathInfo($path);
        return $r;
    }
}
