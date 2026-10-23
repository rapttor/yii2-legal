<?php

declare(strict_types=1);

namespace rapttor\yii2legal;

use Yii;
use yii\helpers\FileHelper;

/**
 * Review gate for legal updates. After `composer update rapttor/legal` a document may have a new version. With
 * Legal::$requireApproval the site keeps serving the last APPROVED version (from the render archive) until the owner runs
 * `php yii legal/approve`. The first run approves the current state automatically.
 */
final class Approval
{
    public function __construct(private Legal $legal)
    {
    }

    private function file(): string
    {
        return Yii::getAlias($this->legal->archivePath) . '/approved.json';
    }

    /** @return array{release:string,at:int,docs:array<string,string>}|null docs: "lang/doc" => version */
    public function state(): ?array
    {
        $f = $this->file();
        $d = is_file($f) ? json_decode((string)file_get_contents($f), true) : null;
        return is_array($d) ? $d : null;
    }

    /** Record the current versions of every offered document as approved. */
    public function approveCurrent(): array
    {
        $docs = [];
        foreach ($this->legal->offered() as $lang) {
            foreach ($this->legal->documents($lang) as $d) {
                if (!$d['fallback']) {
                    $docs[$d['lang'] . '/' . $d['id']] = $d['version'];
                }
            }
        }
        $state = ['release' => $this->legal->release(), 'at' => time(), 'docs' => $docs];
        FileHelper::createDirectory(dirname($this->file()));
        file_put_contents($this->file(), json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n");
        return $state;
    }

    /** Approved version of a document in a language, or null. */
    public function approvedVersion(string $lang, string $id): ?string
    {
        return $this->state()['docs']["$lang/$id"] ?? null;
    }

    /** Documents whose current version differs from the approved one. @return list<array{lang:string,id:string,approved:?string,current:string}> */
    public function changes(): array
    {
        $state = $this->state();
        $out = [];
        foreach ($this->legal->offered() as $lang) {
            foreach ($this->legal->documents($lang) as $d) {
                if ($d['fallback']) {
                    continue;
                }
                $was = $state['docs']["{$d['lang']}/{$d['id']}"] ?? null;
                if ($was !== $d['version']) {
                    $out[] = ['lang' => $d['lang'], 'id' => $d['id'], 'approved' => $was, 'current' => $d['version']];
                }
            }
        }
        return $out;
    }
}
