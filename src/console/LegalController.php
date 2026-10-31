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
 * `yii legal/agents`  writes the security and compliance checklist for AI coding agents, filtered to this app's features, into AGENTS.md
 *                     (`--file=path`, `--llms` also writes llms.txt). Re-run after changing features; only the marked block is replaced.
 * `yii legal/export <dir> [html|md]`  writes every published document per language.
 */
final class LegalController extends Controller
{
    /** Show changed lines in `legal/status`. */
    public bool $diff = false;
    /** Do not fail `legal/check` because of unconfirmed claims. */
    public bool $skipClaims = false;

    /** Target file of `legal/agents` (default: AGENTS.md in the application base path). */
    public ?string $file = null;
    /** `legal/agents`: also write or update llms.txt next to it. */
    public bool $llms = false;

    public string $status = 'done';
    public ?string $note = null;

    public function options($actionID): array
    {
        return array_merge(parent::options($actionID), ['diff', 'skipClaims', 'file', 'llms', 'status', 'note']);
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
        $hints = $legal->hints();
        if ($hints !== []) {
            $this->stdout("\nHints (not errors):\n  - " . implode("\n  - ", $hints) . "\n");
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

    /** Open privacy and legal requests, soonest due first: `yii legal/requests`. */
    public function actionRequests(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $rows = $legal->requests()->open();
        if ($rows === []) {
            $this->stdout("No open requests.\n");
            return ExitCode::OK;
        }
        foreach ($rows as $r) {
            $due = $r['due_at'] === null ? 'no due date' : 'due ' . gmdate('Y-m-d', (int)$r['due_at']) . ((int)$r['due_at'] < time() ? '  OVERDUE' : '');
            $this->stdout(sprintf("%s  %-9s %-30s %s\n    %s\n", \rapttor\yii2legal\Requests::ref((int)$r['id']), $r['type'], $r['email'], $due, mb_substr(str_replace("\n", ' ', (string)$r['message']), 0, 100)));
        }
        return ExitCode::OK;
    }

    /** Mark a request handled: `yii legal/request-close 12 --status=done --note="exported and sent"`. */
    public function actionRequestClose(int $id): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $ok = $legal->requests()->close($id, $this->status, $this->note);
        $this->stdout($ok ? "Closed.\n" : "No open request with that id.\n");
        return $ok ? ExitCode::OK : ExitCode::DATAERR;
    }

    public function actionApprove(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $state = $legal->approval()->approveCurrent();
        $this->stdout('Approved ' . count($state['docs']) . ' documents of release ' . $state['release'] . ".\n");
        return ExitCode::OK;
    }

    public function actionAgents(): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $missing = [];
        $text = $legal->library()->internal($legal->effectiveConfig(), 'app_checklist', $missing) . "\n\n" . $this->restrictionsText($legal);
        $file = Yii::getAlias($this->file ?? '@app/AGENTS.md');
        $this->writeBlock($file, "# Instructions for AI coding agents\n\n", $text);
        $this->stdout("wrote the checklist into $file\n");
        if ($this->llms) {
            $llms = dirname($file) . '/llms.txt';
            $name = $legal->productName();
            $activities = trim((string)($legal->effectiveConfig()['policy']['activities'] ?? '')) ?: 'none set yet';
            $this->writeBlock($llms, "# $name\n\n", "> Application using rapttor/yii2-legal (legal pages, consents, privacy requests). AI coding agents: read " . basename($file) . " before changing anything.\n\n"
                . "- [" . basename($file) . "](" . basename($file) . "): security and compliance checklist for this app (row-level access, secrets, uploads, consents, third-party resources, email, payments, requests)\n"
                . "- Legal pages: published from the legal config (`common/config/legal.php`); `php yii legal/check` must pass\n"
                . "- Consents: every item is its own unticked checkbox with a short title and small-print hint; new accounts tick them in the sign-in step (`ConsentChecklist`), existing accounts when a version changes (`ConsentGate`); the record is kept in `rapttor_metadata` (`key=legal`)\n"
                . "- Activities that some countries prohibit (`policy.activities`, now: $activities): see the generated section in " . basename($file) . "; add the tag when a feature needs it\n"
                . "- Privacy and legal requests: `/legal/request`, alert admins through `Legal::EVENT_REQUEST`, list with `php yii legal/requests`; never promise reply times\n"
                . "- Languages: use `Yii::\$app->legal->url()` and the language strip; Balkan texts follow rapttor/legal `i18n/BRIEF-sh.md`\n");
            $this->stdout("wrote $llms\n");
        }
        return ExitCode::OK;
    }

    /** Activity tags and the countries that prohibit them, as Markdown for the agent files. */
    private function restrictionsText(Legal $legal): string
    {
        $lib = $legal->library();
        $out = "## Activities that some countries prohibit (tags for `policy.activities`)\n\n"
            . "If the app does any of these things, add the tag to `policy.activities` in the legal config (comma separated, for example `gambling,alcohol,sanctions`; `feature.gambling` adds `gambling` by itself). "
            . "Visitors then tick one box, \"I am not located in, and am not a resident or national of: ...\", listing the countries below (status `prohibited`) in their own language. Do not claim to block those countries (VPNs): the checkbox is the record. "
            . "Most apps should add `sanctions`. The lists are a research starting point with a confidence level per country: counsel confirms them, and an owner can add countries by hand in `policy.excluded_regions`. Statuses `licensed` and `restricted` are not asked by default (`policy.restriction_status`).\n\n";
        foreach ($lib->restrictionTags() as $tag) {
            $d = $lib->restrictions($tag);
            $names = array_values($lib->restrictedCountries([$tag]));
            $conf = array_count_values(array_column(array_filter($d['countries'], static fn(array $c): bool => $c['status'] === 'prohibited'), 'confidence'));
            $out .= "- `$tag`: {$d['title']}. Prohibited (" . count($names) . ', confidence high ' . ($conf['high'] ?? 0) . ', medium ' . ($conf['medium'] ?? 0) . ', low ' . ($conf['low'] ?? 0) . '): ' . ($names === [] ? 'none listed' : implode(', ', $names)) . ". Checked {$d['checked']}.\n";
        }
        return $out;
    }

    /** List the restrictions dataset: `yii legal/restrictions` (tags) or `yii legal/restrictions gambling` (countries with status, scope, confidence, source). */
    public function actionRestrictions(?string $tag = null): int
    {
        /** @var Legal $legal */
        $legal = Yii::$app->get('legal');
        $lib = $legal->library();
        if ($tag === null) {
            foreach ($lib->restrictionTags() as $t) {
                $d = $lib->restrictions($t);
                $this->stdout(sprintf("%-10s %3d countries  %s (checked %s)\n", $t, count($d['countries']), $d['title'], $d['checked']));
            }
            return ExitCode::OK;
        }
        $d = $lib->restrictions($tag);
        if ($d === null) {
            $this->stderr("Unknown tag. Known: " . implode(', ', $lib->restrictionTags()) . "\n");
            return ExitCode::DATAERR;
        }
        $this->stdout($d['title'] . "\n" . $d['summary'] . "\n\n");
        foreach ($d['countries'] as $c) {
            $this->stdout(sprintf("%s %-26s %-10s %-8s %-6s %s\n", $c['code'], $c['name'], $c['status'], $c['scope'], $c['confidence'], $c['source'] ?? ''));
        }
        return ExitCode::OK;
    }

    /** Replace the generated block between the markers, or append it; the rest of the file is untouched. */
    private function writeBlock(string $file, string $header, string $content): void
    {
        $begin = '<!-- rapttor-legal:begin (generated by `php yii legal/agents`; change the legal config, not this block) -->';
        $end = '<!-- rapttor-legal:end -->';
        $block = $begin . "\n" . trim($content) . "\n" . $end . "\n";
        $old = is_file($file) ? (string)file_get_contents($file) : $header;
        $pattern = '/' . preg_quote('<!-- rapttor-legal:begin', '/') . '.*?' . preg_quote($end, '/') . "\n?/s";
        $new = preg_match($pattern, $old) ? (string)preg_replace_callback($pattern, static fn() => $block, $old, 1) : rtrim($old) . "\n\n" . $block;
        file_put_contents($file, $new);
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
