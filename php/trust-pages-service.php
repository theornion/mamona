<?php

declare(strict_types=1);

const TRUST_PUBLIC_PAGES = [
    'o-serwisie.html' => 'O serwisie',
    'autorzy.html' => 'Autorzy',
    'polityka-redakcyjna.html' => 'Polityka redakcyjna',
    'jak-uzywamy-ai.html' => 'Jak używamy AI',
    'korekty-i-aktualizacje.html' => 'Korekty i aktualizacje',
    'kontakt.html' => 'Kontakt',
    'polityka-prywatnosci.html' => 'Polityka prywatności',
];

function trust_escape(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function trust_is_production(): bool
{
    return in_array(strtolower((string) app_config('environment')), ['production', 'prod'], true);
}

function trust_author_filename(array $author): string
{
    $slug = trim((string) ($author['slug'] ?? ''));
    if ($slug === '') {
        $slug = 'autor-' . max(1, (int) ($author['id'] ?? 0));
    }

    return 'autor-' . rawurlencode($slug) . '.html';
}

function trust_public_page_filenames(): array
{
    $filenames = array_keys(TRUST_PUBLIC_PAGES);
    foreach (list_authors(true) as $author) {
        $filenames[] = trust_author_filename($author);
    }

    return array_values(array_unique($filenames));
}

function trust_footer_navigation_html(): string
{
    $links = [];
    foreach (TRUST_PUBLIC_PAGES as $filename => $label) {
        $links[] = '<li><a href="' . trust_escape($filename) . '">' . trust_escape($label) . '</a></li>';
    }

    return '<nav class="trust-footer-links" aria-label="Informacje o serwisie"><ul>'
        . implode('', $links) . '</ul></nav>';
}

function trust_placeholder(string $message): string
{
    return '<aside class="trust-placeholder" role="note"><strong>Do uzupełnienia przed publikacją produkcyjną:</strong> '
        . trust_escape($message) . '</aside>';
}

function trust_contact_values(): array
{
    $settings = get_contact_settings();
    $editorialEmail = trim((string) app_config('editorial_contact_email'));
    $privacyEmail = trim((string) app_config('privacy_contact_email'));
    $fallbackEmail = trim((string) ($settings['email'] ?? ''));

    return [
        'legal_name' => trim((string) app_config('publisher_legal_name')),
        'address' => trim((string) ($settings['address'] ?? '')),
        'phone' => trim((string) ($settings['phone'] ?? '')),
        'editorial_email' => $editorialEmail !== '' ? $editorialEmail : $fallbackEmail,
        'privacy_email' => $privacyEmail !== '' ? $privacyEmail : $fallbackEmail,
        'retention' => trim((string) app_config('contact_retention_policy')),
    ];
}

function trust_contact_form_html(): string
{
    return '<section class="trust-contact-form-section" aria-labelledby="contact-form-title">'
        . '<h2 id="contact-form-title">Napisz do nas</h2>'
        . '<form id="contactForm" class="trust-contact-form" method="post" action="../php/contact.php" data-turnstile-sitekey="">'
        . '<div class="fields">'
        . '<div class="field"><label for="name">Imię</label><input type="text" name="name" id="name" autocomplete="name" placeholder="Wpisz swoje imię..." required></div>'
        . '<div class="field"><label for="email">E-mail</label><input type="email" name="email" id="email" autocomplete="email" inputmode="email" placeholder="Wpisz swój adres e-mail..." required></div>'
        . '<div class="field"><label for="subject">Temat</label><input type="text" name="subject" id="subject" maxlength="150" placeholder="Wpisz temat wiadomości..." required></div>'
        . '<div class="field"><label for="message">Wiadomość</label><textarea name="message" id="message" rows="5" required></textarea></div>'
        . '<div class="field hp-field" aria-hidden="true"><label for="website">Strona WWW</label><input type="text" name="website" id="website" tabindex="-1" autocomplete="off"></div>'
        . '</div><ul class="actions"><li class="contact-action-row"><input type="submit" value="Wyślij wiadomość">'
        . '<div id="contactCaptcha" class="contact-captcha" hidden></div>'
        . '<input type="hidden" name="cf-turnstile-response" id="cf-turnstile-response">'
        . '<span id="contactStatus" class="contact-status" aria-live="polite"></span></li></ul>'
        . '<p class="contact-privacy-note">Wysyłając wiadomość, przekazujesz dane potrzebne do jej obsługi. '
        . 'Szczegóły znajdziesz w <a href="polityka-prywatnosci.html">polityce prywatności</a>.</p>'
        . '</form></section>';
}

function trust_configuration_issues(?bool $production = null): array
{
    $production ??= trust_is_production();
    $values = trust_contact_values();
    $missing = [];

    foreach ([
        'legal_name' => 'tożsamość administratora/wydawcy (CMS_PUBLISHER_LEGAL_NAME)',
        'address' => 'adres wydawcy (Ustawienia kontaktu)',
        'editorial_email' => 'e-mail redakcji (CMS_EDITORIAL_CONTACT_EMAIL lub Ustawienia kontaktu)',
        'privacy_email' => 'e-mail prywatności (CMS_PRIVACY_CONTACT_EMAIL lub Ustawienia kontaktu)',
        'retention' => 'okres lub kryteria retencji zgłoszeń (CMS_CONTACT_RETENTION_POLICY)',
    ] as $key => $label) {
        if ($values[$key] === '') {
            $missing[] = $label;
        }
    }
    foreach (['editorial_email', 'privacy_email'] as $key) {
        if ($values[$key] !== '' && filter_var($values[$key], FILTER_VALIDATE_EMAIL) === false) {
            $missing[] = ($key === 'editorial_email' ? 'poprawny e-mail redakcji' : 'poprawny e-mail prywatności');
        }
    }

    $authorsWithoutBio = [];
    foreach (list_authors(true) as $author) {
        if (trim((string) ($author['bio'] ?? '')) === '') {
            $authorsWithoutBio[] = (string) $author['name'];
        }
    }
    if ($authorsWithoutBio !== []) {
        $missing[] = 'prawdziwy opis aktywnych autorów: ' . implode(', ', $authorsWithoutBio);
    }

    if ($missing === []) {
        return [];
    }

    return [[
        'level' => $production ? 'error' : 'warning',
        'message' => ($production ? 'BLOKADA STARTU PRODUKCYJNEGO' : 'Przed startem produkcyjnym')
            . ' — uzupełnij strony zaufania: ' . implode('; ', $missing) . '.',
    ]];
}

function assert_trust_configuration_allows_publication(?bool $production = null): void
{
    $production ??= trust_is_production();
    if (!$production) {
        return;
    }
    $issues = trust_configuration_issues(true);
    if ($issues !== []) {
        throw new RuntimeException((string) $issues[0]['message']);
    }
}

function trust_render_about(): string
{
    $values = trust_contact_values();
    $identity = $values['legal_name'] !== ''
        ? '<p>Za serwis odpowiada <strong>' . trust_escape($values['legal_name']) . '</strong>.</p>'
        : trust_placeholder('podaj pełną nazwę podmiotu odpowiedzialnego za serwis.');

    return '<p>Publikujemy przystępne materiały o technologii i nauce. Naszym celem jest wyjaśnianie, '
        . 'co się wydarzyło, jak działa opisywane rozwiązanie i jakie ma praktyczne znaczenie.</p>'
        . $identity
        . '<h2>Jak pracujemy</h2><p>Tematy wybiera redakcja na podstawie aktualności ze źródeł technicznych. '
        . 'Przed publikacją materiał przechodzi kontrolę źródeł, języka, obrazu i wymaganych oznaczeń.</p>'
        . '<p><a href="polityka-redakcyjna.html">Przeczytaj politykę redakcyjną</a> i '
        . '<a href="jak-uzywamy-ai.html">zasady używania AI</a>.</p>';
}

function trust_render_authors(): string
{
    $items = [];
    foreach (list_authors(true) as $author) {
        $name = trust_escape((string) $author['name']);
        $bio = trim((string) ($author['bio'] ?? ''));
        $items[] = '<article class="trust-author-card"><h2><a href="' . trust_escape(trust_author_filename($author)) . '">'
            . $name . '</a></h2>'
            . ($bio !== '' ? '<p>' . nl2br(trust_escape($bio)) . '</p>' : trust_placeholder('uzupełnij prawdziwy opis autora „' . (string) $author['name'] . '”.'))
            . '</article>';
    }

    return '<p>Za każdy artykuł odpowiada wskazany przy nim autor lub zespół redakcyjny. '
        . 'Nie publikujemy fikcyjnych biogramów ani nieprzypisanych kompetencji.</p>'
        . ($items !== [] ? implode('', $items) : trust_placeholder('dodaj co najmniej jednego prawdziwego autora lub zespół redakcyjny.'));
}

function trust_render_editorial_policy(): string
{
    return '<p>Ta polityka opisuje standard obowiązujący przy przygotowaniu materiałów.</p>'
        . '<h2>Źródła i weryfikacja</h2><ul>'
        . '<li>Preferujemy źródła pierwotne: dokumentację, publikacje naukowe, komunikaty instytucji i producentów.</li>'
        . '<li>Istotne twierdzenia zestawiamy ze źródłami, a linki źródłowe pokazujemy pod artykułem.</li>'
        . '<li>Oddzielamy fakty od wniosków i nie przedstawiamy informacji prasowej jako niezależnego potwierdzenia.</li>'
        . '</ul><h2>Odpowiedzialność</h2><p>Publikację zatwierdza człowiek. Automatyczne narzędzia mogą wspierać '
        . 'research, redakcję i obraz, ale nie przejmują odpowiedzialności autora ani wydawcy.</p>'
        . '<h2>Aktualizacje</h2><p>Istotne poprawki oznaczamy przy artykule datą aktualizacji i, gdy zmieniają sens '
        . 'materiału, krótką notą. Procedurę zgłoszenia błędu opisujemy na stronie '
        . '<a href="korekty-i-aktualizacje.html">Korekty i aktualizacje</a>.</p>';
}

function trust_render_ai_policy(): string
{
    return '<p>Narzędzia AI mogą wspierać redakcję, ale materiał nie powinien trafiać do publikacji bez kontroli człowieka.</p>'
        . '<h2>Do czego możemy używać AI</h2><ul>'
        . '<li>porządkowanie researchu i proponowanie struktury tekstu,</li>'
        . '<li>przygotowanie roboczej wersji lub redakcja językowa,</li>'
        . '<li>automatyczne testy jakości i wyszukiwanie niespójności,</li>'
        . '<li>tworzenie miniatur; użycie obrazu AI jest zapisywane w danych redakcyjnych.</li>'
        . '</ul><h2>Czego AI nie rozstrzyga</h2><p>AI nie jest źródłem faktu, nie zastępuje lektury wskazanych źródeł '
        . 'i nie podejmuje samodzielnie ostatecznej decyzji o publikacji. Artykuł wsparty AI otrzymuje widoczną informację, '
        . 'jak wykorzystano narzędzia automatyczne.</p>';
}

function trust_render_corrections(): string
{
    $email = trust_contact_values()['editorial_email'];
    $channel = $email !== ''
        ? 'napisz na <a href="mailto:' . trust_escape($email) . '">' . trust_escape($email) . '</a>'
        : 'użyj <a href="kontakt.html">strony kontaktowej</a>';

    return '<p>Jeśli zauważysz błąd, ' . $channel . '. W temacie wpisz <strong>„Korekta: tytuł lub URL”</strong>.</p>'
        . '<h2>Co dołączyć</h2><ol><li>adres artykułu,</li><li>fragment, którego dotyczy zgłoszenie,</li>'
        . '<li>opis błędu i — jeśli masz — wiarygodne źródło potwierdzające korektę.</li></ol>'
        . '<h2>Co dzieje się dalej</h2><p>Redakcja sprawdza zgłoszenie w źródłach. Jeśli korekta jest zasadna, '
        . 'aktualizujemy artykuł. Istotna zmiana otrzymuje datę aktualizacji i notę wyjaśniającą zakres poprawki. '
        . 'Drobne poprawki typograficzne mogą nie otrzymać osobnej noty.</p>';
}

function trust_render_contact(): string
{
    $values = trust_contact_values();
    $parts = [];
    foreach ([
        'legal_name' => 'Podmiot odpowiedzialny',
        'address' => 'Adres',
        'phone' => 'Telefon',
        'editorial_email' => 'E-mail redakcji',
        'privacy_email' => 'E-mail w sprawach prywatności',
    ] as $key => $label) {
        if ($values[$key] === '') {
            $parts[] = trust_placeholder('uzupełnij pole „' . $label . '”.');
            continue;
        }
        $value = trust_escape($values[$key]);
        if (str_contains($key, 'email')) {
            $value = '<a href="mailto:' . $value . '">' . $value . '</a>';
        }
        $parts[] = '<section class="trust-contact-item"><h2>' . trust_escape($label) . '</h2><p>' . $value . '</p></section>';
    }

    return '<p>W sprawie artykułów, współpracy i danych osobowych skorzystaj z poniższych danych albo z formularza '
        . 'na tej stronie.</p>' . implode('', $parts)
        . '<p>Zgłaszasz błąd w tekście? Zobacz <a href="korekty-i-aktualizacje.html">dokładną procedurę korekt</a>.</p>'
        . trust_contact_form_html();
}

function trust_render_privacy(): string
{
    $values = trust_contact_values();
    $identity = $values['legal_name'] !== '' ? trust_escape($values['legal_name']) : '[UZUPEŁNIJ ADMINISTRATORA DANYCH]';
    $privacyEmail = $values['privacy_email'] !== '' ? trust_escape($values['privacy_email']) : '[UZUPEŁNIJ E-MAIL DS. PRYWATNOŚCI]';
    $retention = $values['retention'] !== '' ? trust_escape($values['retention']) : '[UZUPEŁNIJ OKRES LUB KRYTERIA RETENCJI]';
    $hasPlaceholder = in_array('', [$values['legal_name'], $values['privacy_email'], $values['retention']], true);

    return ($hasPlaceholder ? trust_placeholder('ta polityka zawiera oznaczone pola wymagające decyzji właściciela i weryfikacji przed startem.') : '')
        . '<p>Administratorem danych przekazywanych przez formularz kontaktowy jest <strong>' . $identity . '</strong>. '
        . 'Kontakt w sprawach prywatności: <strong>' . $privacyEmail . '</strong>.</p>'
        . '<h2>Jakie dane i w jakim celu</h2><p>Formularz zapisuje imię, adres e-mail, temat i treść wiadomości. '
        . 'Adres IP może być doraźnie przetwarzany przy weryfikacji zabezpieczenia antyspamowego. Dane służą obsłudze '
        . 'zgłoszenia, zapewnieniu bezpieczeństwa i — gdy jest to potrzebne — obronie przed roszczeniami.</p>'
        . '<h2>Podstawa i okres przetwarzania</h2><p>Przed uruchomieniem produkcyjnym właściciel serwisu musi wskazać '
        . 'właściwą podstawę prawną dla każdego celu po analizie faktycznego procesu. Przyjęta retencja zgłoszeń: '
        . '<strong>' . $retention . '</strong>.</p>'
        . '<h2>Odbiorcy i transfery</h2><p>Dane mogą otrzymać dostawcy hostingu, poczty i zabezpieczenia formularza '
        . 'wyłącznie w zakresie potrzebnym do świadczenia usług. Ich nazwy, lokalizacje i ewentualne transfery poza EOG '
        . 'należy uzupełnić po wyborze środowiska produkcyjnego.</p>'
        . '<h2>Twoje prawa</h2><p>W granicach wynikających z RODO możesz żądać dostępu, sprostowania, usunięcia lub '
        . 'ograniczenia danych, a w odpowiednich przypadkach wnieść sprzeciw. Możesz również złożyć skargę do Prezesa UODO. '
        . 'Szczegóły zależą od podstawy i celu przetwarzania.</p>'
        . '<h2>Cookies i reklamy</h2><p>Ta wersja serwisu nie deklaruje jeszcze uruchomienia reklam ani analityki '
        . 'wymagającej zgody. Politykę i mechanizm zgód trzeba zaktualizować przed dodaniem takich dostawców.</p>'
        . '<p>Materiały pomocnicze: <a href="https://uodo.gov.pl/pl/1/2252" rel="noopener noreferrer">informacje UODO</a> '
        . 'oraz <a href="https://eur-lex.europa.eu/eli/reg/2016/679/oj" rel="noopener noreferrer">tekst RODO</a>.</p>';
}

function trust_render_author_profile(array $author): string
{
    $name = trust_escape((string) $author['name']);
    $bio = trim((string) ($author['bio'] ?? ''));
    $profileUrl = trim((string) ($author['profile_url'] ?? ''));
    $external = $profileUrl !== '' && filter_var($profileUrl, FILTER_VALIDATE_URL)
        ? '<p><a href="' . trust_escape($profileUrl) . '" rel="me noopener noreferrer">Zewnętrzny profil autora</a></p>'
        : '';

    return '<p>Profil autora lub zespołu odpowiedzialnego za materiały podpisane nazwą „' . $name . '”.</p>'
        . ($bio !== '' ? '<div class="trust-author-bio"><p>' . nl2br(trust_escape($bio)) . '</p></div>'
            : trust_placeholder('uzupełnij prawdziwy opis autora; nie publikuj fikcyjnego biogramu ani kompetencji.'))
        . $external
        . '<p><a href="autorzy.html">Wszyscy autorzy</a> · '
        . '<a href="polityka-redakcyjna.html">Polityka redakcyjna</a></p>';
}

function trust_page_body(string $filename): string
{
    return match ($filename) {
        'o-serwisie.html' => trust_render_about(),
        'autorzy.html' => trust_render_authors(),
        'polityka-redakcyjna.html' => trust_render_editorial_policy(),
        'jak-uzywamy-ai.html' => trust_render_ai_policy(),
        'korekty-i-aktualizacje.html' => trust_render_corrections(),
        'kontakt.html' => trust_render_contact(),
        'polityka-prywatnosci.html' => trust_render_privacy(),
        default => throw new InvalidArgumentException('Nieznana strona zaufania: ' . $filename),
    };
}

function render_trust_page_html(string $title, string $description, string $body): string
{
    $template = file_get_contents(app_path('pages/index.html'));
    if ($template === false) {
        throw new RuntimeException('Nie można odczytać szablonu stron zaufania.');
    }

    $titleEscaped = trust_escape($title);
    $siteName = trust_escape((string) app_config('site_name'));
    $descriptionEscaped = trust_escape($description);
    $section = '<article class="post featured trust-page"><header class="major news-feed-heading">'
        . '<p class="news-feed-kicker">Transparentność</p><h1>' . $titleEscaped . '</h1>'
        . '<p>' . $descriptionEscaped . '</p></header><div class="trust-page-body">' . $body . '</div></article>';

    $template = str_replace(
        '<body class="is-preload menu-page">',
        '<body class="is-preload menu-page page-no-intro static-header-start">',
        $template
    );
    $template = preg_replace('/<title>.*?<\/title>/s', '<title>' . $titleEscaped . ' | ' . $siteName . '</title>', $template, 1) ?? $template;
    $template = preg_replace('/<meta name="description" content="[^"]*"\s*\/?>/i', '<meta name="description" content="' . $descriptionEscaped . '">', $template, 1) ?? $template;
    $template = preg_replace(
        '/<section class="post featured bueno-newsfeed"[^>]*>.*?<\/section>/s',
        $section,
        $template,
        1
    ) ?? $template;
    $template = preg_replace(
        '/\s*<script defer src="\.\.\/assets\/js\/news-feed\.js\?v=[^"]+"><\/script>/',
        '',
        $template,
        1
    ) ?? $template;
    if ($title === 'Kontakt') {
        $template = str_replace(
            '<script defer src="../assets/js/site-contact.js?v=cms-core-20260721"></script>',
            '<script defer src="../assets/js/contact-form.js?v=cms-core-20260721"></script>' . "\n    "
                . '<script defer src="../assets/js/site-contact.js?v=cms-core-20260721"></script>',
            $template
        );
    }

    return $template;
}

function write_trust_pages(?string $outputDirectory = null): array
{
    $outputDirectory ??= app_path('pages');
    if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0755, true) && !is_dir($outputDirectory)) {
        throw new RuntimeException('Nie można utworzyć katalogu stron zaufania.');
    }

    $written = [];
    foreach (TRUST_PUBLIC_PAGES as $filename => $title) {
        $description = match ($filename) {
            'o-serwisie.html' => 'Kto odpowiada za serwis i jak przygotowujemy materiały.',
            'autorzy.html' => 'Autorzy i zespoły odpowiedzialne za publikowane materiały.',
            'polityka-redakcyjna.html' => 'Zasady źródeł, weryfikacji, publikacji i odpowiedzialności.',
            'jak-uzywamy-ai.html' => 'Jawne zasady używania narzędzi AI w pracy redakcyjnej.',
            'korekty-i-aktualizacje.html' => 'Jak zgłosić błąd i jak oznaczamy istotne poprawki.',
            'kontakt.html' => 'Dane kontaktowe redakcji i administratora serwisu.',
            default => 'Informacje o przetwarzaniu danych i prawach użytkownika.',
        };
        $html = render_trust_page_html($title, $description, trust_page_body($filename));
        $path = rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename;
        write_public_file_atomically($path, $html);
        $written[] = $filename;
    }

    foreach (list_authors(true) as $author) {
        $filename = trust_author_filename($author);
        $html = render_trust_page_html(
            (string) $author['name'],
            'Profil autora lub zespołu odpowiedzialnego za publikowane materiały.',
            trust_render_author_profile($author)
        );
        write_public_file_atomically(rtrim($outputDirectory, '/\\') . DIRECTORY_SEPARATOR . $filename, $html);
        $written[] = $filename;
    }

    return $written;
}
