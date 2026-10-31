<?php

declare(strict_types=1);

namespace rapttor\yii2legal\filters;

/**
 * Kept for apps wired with the first release. It used to show only the "we updated our terms" page for the documents.
 * It now behaves exactly like {@see ConsentGate}: the whole consents checklist (documents, age and legal-age confirmation,
 * upload responsibility, optional choices) is shown, so an app that still attaches this filter gets the checklist after
 * `composer update`. New code should attach ConsentGate:
 *
 *     'as legalConsents' => ['class' => rapttor\yii2legal\filters\ConsentGate::class],
 *
 * @deprecated use ConsentGate
 */
final class AcceptanceFilter extends ConsentGate
{
}
