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
        $this->assertNotContains('sr', $legal->offered(), 'draft translations are hidden by default');

        $legal = $this->legal([], ['publishDrafts' => true]);
        $this->assertContains('sr', $legal->offered());
        $this->assertContains('sr-Cyrl', $legal->offered());
    }

    public function testLanguagesOptionNarrowsAndOrdersTheSwitcher(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true, 'languages' => ['sr', 'en', 'xx', 'ja']]);
        $this->assertSame(['sr', 'en'], $legal->offered(), 'unknown or unavailable tags are ignored, order is kept');
        $this->assertSame('sr', $legal->resolve('sr_RS'));
        $this->assertSame('en', $legal->resolve('hr'), 'a language the site does not offer falls back');
    }

    public function testDocumentsFallBackToEnglishPerDocument(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true]);
        $d = $legal->document('cookies', 'sr');
        $this->assertSame('sr', $d['lang']);
        $this->assertFalse($d['fallback']);
        $this->assertStringContainsString('kolačić', mb_strtolower($legal->html('cookies', 'sr')));
        $this->assertSame('en', $legal->document('cookies', 'de')['lang'], 'untranslated language shows the English document');
        $this->assertTrue($legal->document('cookies', 'de')['fallback']);
    }

    public function testCyrillicIsGeneratedFromSerbianLatin(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true]);
        $html = $legal->html('cookies', 'sr-Cyrl');
        $this->assertMatchesRegularExpression('/[\x{0400}-\x{04FF}]{4,}/u', $html);
        $this->assertStringContainsString('id="categories"', $html, 'anchors stay Latin');
        $this->assertStringContainsString('href="/legal/sr-Cyrl/', $html, 'links keep the language');
    }

    public function testLanguageAwareUrlsAndRoutes(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true]);
        $this->assertSame('/legal/terms', $legal->url('terms', 'en'));
        $this->assertSame('/legal/sr/terms', $legal->url('terms', 'sr'));
        (new Bootstrap())->bootstrap(Yii::$app);
        $this->assertSame(['legal/view', ['lang' => 'sr', 'doc' => 'cookies']], Yii::$app->urlManager->parseRequest($this->request('legal/sr/cookies')));
        $this->assertSame(['legal/index', ['lang' => 'sr']], Yii::$app->urlManager->parseRequest($this->request('legal/sr')));
        $this->assertSame(['legal/view', ['doc' => 'privacy']], Yii::$app->urlManager->parseRequest($this->request('legal/privacy')));
    }

    public function testControllerServesLanguageAndRejectsUnofferedOnes(): void
    {
        $legal = $this->legal([], ['publishDrafts' => true, 'languages' => ['en', 'sr']]);
        (new Bootstrap())->bootstrap(Yii::$app);
        $out = (string)Yii::$app->runAction('legal/view', ['doc' => 'cookies', 'lang' => 'sr']);
        $this->assertStringContainsString('lang="sr"', $out);
        $this->assertStringContainsString('Politika kolačića', $out);
        $this->assertSame('sr', Yii::$app->response->headers->get('Content-Language'));
        $this->assertArrayHasKey('hreflang-x-default', Yii::$app->view->linkTags);

        $out = (string)Yii::$app->runAction('legal/view', ['doc' => 'cookies', 'lang' => 'en']);
        $this->assertStringContainsString('Cookie Policy', $out);

        $this->expectException(NotFoundHttpException::class);
        Yii::$app->runAction('legal/view', ['doc' => 'cookies', 'lang' => 'hr']);
    }

    public function testLanguageSwitcherListsOfferedAndHonoursOnly(): void
    {
        $this->legal([], ['publishDrafts' => true, 'languages' => ['en', 'sr', 'sr-Cyrl']]);
        $all = \rapttor\yii2legal\widgets\LanguageSwitcher::widget(['doc' => 'cookies']);
        $this->assertStringContainsString('Srpski (latinica)', $all);
        $this->assertStringContainsString('Српски (ћирилица)', $all);
        $this->assertStringContainsString('href="/legal/sr/cookies"', $all);
        $this->assertStringContainsString('aria-current', $all);

        $only = \rapttor\yii2legal\widgets\LanguageSwitcher::widget(['doc' => 'cookies', 'only' => ['sr', 'en']]);
        $this->assertStringNotContainsString('Српски', $only);
        $this->assertLessThan(strpos($only, 'English'), strpos($only, 'Srpski'), 'order follows "only"');

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
