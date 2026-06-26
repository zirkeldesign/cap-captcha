Deutsche Übersetzung der readme.txt für translate.wordpress.org
(Projekt „Stable Readme (latest release)“ → Deutsch). Nicht Teil des Plugins —
diese Datei dient nur als Vorlage zum Einfügen in GlotPress.

== Kurzbeschreibung ==

Datenschutzfreundlicher Spamschutz für Kommentare, Anmeldung, Registrierung,
WooCommerce und Gravity Forms – betrieben über deinen eigenen Cap-Server.

== Beschreibung ==

**Privacy CAPTCHA for Cap** integriert [Cap](https://trycap.dev/) – ein
selbst gehostetes, datenschutzfreundliches Proof-of-Work-CAPTCHA – in die
Bereiche von WordPress, die am meisten Spam anziehen: Kommentare, Anmeldung,
Benutzerregistrierung, WooCommerce-Kasse und Gravity Forms.

Anders als Drittanbieter-CAPTCHAs (reCAPTCHA, hCaptcha, Turnstile) führt Cap
das Proof-of-Work vollständig im Browser der Besucher:innen aus und prüft das
Token gegen deinen eigenen Cap-Server. Es verlassen keine Daten deine
Infrastruktur.

> **Inoffizielle Integration.** Cap ist ein unabhängiges Open-Source-Projekt
> von tiagozip (https://trycap.dev/, Apache-2.0). Dieses Plugin ist eine
> Drittanbieter-Integration und steht in **keiner Verbindung zum Cap-Projekt,
> wird von ihm nicht unterstützt oder gesponsert**.

= Funktionen =

* **Erstklassiges Gravity-Forms-Feld** – ziehe ein „Privacy CAPTCHA for Cap“
  aus der Gruppe „Erweiterte Felder“ in jedes Formular. Anzeigemodus pro Feld
  überschreibbar.
* **Contact Form 7** – schützt automatisch jedes CF7-Formular.
* **WordPress-Kommentare, wp-login, Registrierung** – über eine zentrale
  Einstellungsseite aktivierbar.
* **WooCommerce** – Kasse sowie die Formulare „Mein Konto“ für Anmeldung,
  Registrierung und Passwort vergessen, jeweils einzeln schaltbar. Wird nur
  geladen, wenn WooCommerce aktiv ist.
* **Dashboard-Widget** – Cap-Server-Statistiken auf einen Blick (Challenges,
  bestätigt, fehlgeschlagen, stündliches Diagramm) direkt im WordPress-Dashboard.
* **Granulare Schalter pro Oberfläche** und **Entwickler-Filter**
  (`cap_captcha_protect`), um den Schutz für jedes Formular – auch bedingt –
  zu aktivieren oder zu deaktivieren.
* **Drei Anzeigemodi**: Inline-Widget, schwebendes Popover oder vollständig
  programmatisch (löst still im Hintergrund).
* **Vollständig selbst gehostete Assets** – das Proof-of-Work-WebAssembly-Modul,
  das cap-widget-Skript und die pako-Bibliothek liegen im Plugin und werden
  lokal ausgeliefert; zur Laufzeit wird kein jsdelivr oder anderes Drittanbieter-
  CDN kontaktiert. Standardmäßig DSGVO-konform.
* **Native Skript-Modul-API ab WP 6.5** für sauberes ES-Modul-Laden.
* **Übersetzungsbereit** mit mitgelieferten deutschen Übersetzungen.
* **Theme-freundliches CSS** auf Basis der Gravity-Forms-Orbital-Tokens mit
  `--cap-captcha-*`-Overrides.
* **Filter-Hooks** für Schutzsteuerung, Asset-URLs, Button-Klassen,
  i18n-Strings und den Anzeigemodus.
