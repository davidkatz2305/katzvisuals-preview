<?php
/**
 * Kontaktformular katzvisuals.de (STRATO, PHP 8, ohne Abhängigkeiten).
 *
 * Nimmt den POST des Formulars auf /kontakt/ entgegen, prüft die Angaben und schickt sie
 * per mail() an info@katzvisuals.de. Erfolg: 303 auf /danke/, Fehler: 303 auf /kontakt/?fehler=1.
 * Feldnamen: thema, name, firma, email, telefon, nachricht, zeitraum, quelle, datenschutz,
 * firma_web (Honeypot, muss leer bleiben).
 *
 * Auf der GitHub-Pages-Vorschau läuft kein PHP, dort funktioniert der Versand nicht.
 */

declare(strict_types=1);

const EMPFAENGER = 'info@katzvisuals.de';
const ABSENDER   = 'info@katzvisuals.de'; // STRATO verlangt eine Absenderadresse der eigenen Domain

const THEMEN = [
    'imagefilm'    => 'Imagefilm & Werbung',
    'recruiting'   => 'Recruiting-Video',
    'social'       => 'Social Media & Personal Brand',
    'produktvideo' => 'Produktvideo',
    'eventfilm'    => 'Eventfilm',
    'studio'       => 'Studio-Produktion in Berlin',
    'anderes'      => 'Etwas anderes',
];

// Basis-Pfad aus dem Ort dieser Datei ableiten (liegt im Wurzelverzeichnis der Website)
$basis = rtrim(str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? '/')), '/');

function weiter(string $ziel): void
{
    header('Location: ' . $ziel, true, 303);
    header('Cache-Control: no-store');
    exit;
}

/** Einzeiliger Wert: Steuerzeichen und Zeilenumbrüche raus (verhindert Header-Injection), Länge begrenzen. */
function zeile(string $feld, int $max = 200): string
{
    $wert = (string) ($_POST[$feld] ?? '');
    $wert = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $wert) ?? '';
    return trim(mb_substr($wert, 0, $max));
}

/** Mehrzeiliger Wert: Zeilenumbrüche vereinheitlichen, übrige Steuerzeichen entfernen. */
function text(string $feld, int $max = 5000): string
{
    $wert = str_replace(["\r\n", "\r"], "\n", (string) ($_POST[$feld] ?? ''));
    $wert = preg_replace('/[\x00-\x08\x0B-\x1F\x7F]+/u', '', $wert) ?? '';
    return trim(mb_substr($wert, 0, $max));
}

function kopfzeile(string $s): string
{
    if (function_exists('mb_encode_mimeheader')) {
        return mb_encode_mimeheader($s, 'UTF-8', 'B', "\r\n");
    }
    return '=?UTF-8?B?' . base64_encode($s) . '?=';
}

date_default_timezone_set('Europe/Berlin');

if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    weiter($basis . '/kontakt/');
}

// Honeypot: Bots füllen das unsichtbare Feld aus. Still „Erfolg“ melden, nichts senden.
if (trim((string) ($_POST['firma_web'] ?? '')) !== '') {
    weiter($basis . '/danke/');
}

$themaId   = zeile('thema', 40);
$thema     = THEMEN[$themaId] ?? 'Nicht angegeben';
$name      = zeile('name', 120);
$firma     = zeile('firma', 160);
$email     = zeile('email', 200);
$telefon   = zeile('telefon', 60);
$nachricht = text('nachricht');
$zeitraum  = zeile('zeitraum', 80);
$quelle    = zeile('quelle', 80);
$datenschutz = isset($_POST['datenschutz']);

// Pflichtfelder prüfen
$gueltig = mb_strlen($name) >= 2
    && filter_var($email, FILTER_VALIDATE_EMAIL) !== false
    && mb_strlen($nachricht) >= 2
    && $datenschutz;

if (!$gueltig) {
    weiter($basis . '/kontakt/?fehler=1');
}

$betreff = 'Anfrage über katzvisuals.de: ' . $thema . ' – ' . $name;

$leer = static fn (string $v): string => $v !== '' ? $v : '–';
$inhalt = implode("\n", [
    'Neue Anfrage über das Kontaktformular auf katzvisuals.de',
    '',
    'Thema:     ' . $thema,
    'Name:      ' . $name,
    'Firma:     ' . $leer($firma),
    'E-Mail:    ' . $email,
    'Telefon:   ' . $leer($telefon),
    'Zeitraum:  ' . $leer($zeitraum),
    'Gefunden:  ' . $leer($quelle),
    'Gesendet:  ' . date('d.m.Y H:i'),
    '',
    'Nachricht:',
    $nachricht,
    '',
    '-- ',
    'Antworten Sie direkt auf diese E-Mail, die Antwort geht an ' . $email . '.',
]);

$kopf = [
    'From'                      => 'Katz Visuals Website <' . ABSENDER . '>',
    'Reply-To'                  => $email,
    'MIME-Version'              => '1.0',
    'Content-Type'              => 'text/plain; charset=UTF-8',
    'Content-Transfer-Encoding' => 'quoted-printable',
    'X-Mailer'                  => 'katzvisuals.de Kontaktformular',
];

$ok = mail(
    EMPFAENGER,
    kopfzeile($betreff),
    quoted_printable_encode(str_replace("\n", "\r\n", $inhalt)),
    $kopf,
    '-f ' . ABSENDER
);

weiter($basis . ($ok ? '/danke/' : '/kontakt/?fehler=1'));
