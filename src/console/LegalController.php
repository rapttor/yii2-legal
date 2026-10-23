<?php

declare(strict_types=1);

namespace rapttor\yii2legal\console;

use rapttor\yii2legal\Legal;
use Yii;
use yii\console\Controller;
use yii\console\ExitCode;
use yii\helpers\FileHelper;

/**
 * `yii legal/check`    validates config, lists published documents per language, and reports claims the owner has not confirmed (exit 1; for CI).
 * `yii legal/claims`   lists the statements the documents make about the product, with their ids to put in Legal::$confirmedClaims.
 * `yii legal/status`   documents whose version changed since the last approval (`--diff` shows added and removed lines).
 * `yii legal/approve`  approves the current versions (used with Legal::$requireApproval).
 * `yii legal/export <dir> [html|md]`  writes every published document per language.
 */
final class LegalController extends Controller
{
    /** Show changed lines in `legal/status`. */
    public bool $diff = false;
    /** Do not fail `legal/check` because of unconfirmed claims. */
    public bool $skipClaims = false;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['diff', 'skipClaims']);
    }

    public function actionCheck(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $missing = $legal->missing();
        if ($missing !== []) {
            $this->stderr("Missing required config:\n  " . implode("\n  ", $missing) . "\n");
            return ExitCode::UNSPECIFIED_ERROR;
        }
        $this->stdout('rapttor/legal release ' . $legal->release() . ', offered languages: ' . implode(', ', $legal->offered()) . "\n");
        foreach ($legal->offered() as $lang) {
            $this->stdout("\n[$lang]\n");
            foreach ($legal->documents($lang) as $d) {
                $note = $d['fallback'] ? ' (shown in ' . $d['lang'] . ')' : '';
                $this->stdout(sprintf("  %-15s v%-6s %s%s\n", $d['id'], $d['version'], $legal->url($d['id'], $lang), $note));
                $legal->markdown($d['id'], $lang);
            }
        }
        $open = array_filter($legal->claims(), static fn($c) => !$c['confirmed']);
        if ($open !== [] && !$this->skipClaims) {
            $this->stderr("\n" . count($open) . " claim(s) in the published documents are not confirmed. Check each against the product, then add the id to Legal::\$confirmedClaims (run `yii legal/claims`):\n");
            foreach ($open as $c) {
                $this->stderr("  {$c['id']}  [{$c['doc']}]  {$c['text']}\n");
            }
            return ExitCode::UNSPECIFIED_ERROR;
        }
        return ExitCode::OK;
    }

    public function actionClaims(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        foreach ($legal->claims() as $c) {
            $this->stdout(sprintf("[%s] %-32s %-15s %s\n", $c['confirmed'] ? 'x' : ' ', $c['id'], $c['doc'], $c['text']));
        }
        return ExitCode::OK;
    }

    public function actionStatus(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $approval = $legal->approval();
        $state = $approval->state();
        $this->stdout($state === null ? "Nothing approved yet.\n" : 'Approved release ' . $state['release'] . ' on ' . gmdate('Y-m-d H:i', $state['at']) . " UTC.\n");
        $changes = $approval->changes();
        if ($changes === []) {
            $this->stdout("No changes since the last approval.\n");
            return ExitCode::OK;
        }
        foreach ($changes as $c) {
            $this->stdout(sprintf("  %-8s %-15s %s -> %s\n", $c['lang'], $c['id'], $c['approved'] ?? 'new', $c['current']));
            if ($this->diff && $c['approved'] !== null) {
                $old = $this->archived($legal, $c['lang'], $c['id'], $c['approved']);
                $new = $legal->markdown($c['id'], $c['lang']);
                if ($old !== null) {
                    $o = explode("\n", $old);
                    $n = explode("\n", $new);
                    foreach (array_diff($o, $n) as $line) {
                        $this->stdout("      - $line\n");
                    }
                    foreach (array_diff($n, $o) as $line) {
                        $this->stdout("      + $line\n");
                    }
                }
            }
        }
        $this->stdout("\nA MAJOR or MINOR change means rights or clauses changed: tell your users as your Terms and Privacy Policy require, then run `yii legal/approve`.\n");
        return ExitCode::OK;
    }

    public function actionApprove(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $state = $legal->approval()->approveCurrent();
        $this->stdout('Approved ' . count($state['docs']) . ' documents of release ' . $state['release'] . ".\n");
        return ExitCode::OK;
    }

    public function actionExport(string $dir, string $format = 'html'): int
    {
        if (!in_array($format, ['html', 'md'], true)) {
            $this->stderr("format must be html or md\n");
            return ExitCode::USAGE;
        }
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $dir = Yii::getAlias($dir);
        foreach ($legal->offered() as $lang) {
            FileHelper::createDirectory("$dir/$lang");
            foreach ($legal->documents($lang) as $d) {
                $body = $format === 'html' ? $legal->html($d['id'], $lang) : $legal->markdown($d['id'], $lang);
                file_put_contents("$dir/$lang/" . Legal::slug($d['id']) . ".$format", $body);
                $this->stdout("wrote $lang/" . Legal::slug($d['id']) . ".$format\n");
            }
        }
        return ExitCode::OK;
    }

    private function archived(Legal $legal, string $lang, string $id, string $version): ?string
    {
        $files = glob(Yii::getAlias($legal->archivePath) . "/*-$lang-$id-v$version-*.md") ?: [];
        sort($files);
        return $files === [] ? null : (string)file_get_contents(end($files));
    }
}
