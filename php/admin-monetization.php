<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        try {
            update_advertising_settings([
                'ad_slot_offset' => (string) ($_POST['ad_slot_offset'] ?? ''),
            ]);
            header('Location: admin-monetization.php?saved=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$settings = get_advertising_settings();
$offset = max(-2, min(2, (int) ($settings['ad_slot_offset'] ?? 0)));
$offsetLabel = $offset === 0 ? 'W' : 'W' . ($offset > 0 ? '+' : '') . $offset;
$exampleCount = advertising_slot_count_from_visual_count(4, $offset);

admin_page_open('Ustawienia monetyzacji', 'monetization');
?>
<section class="post admin-card">
    <header class="major admin-heading">
        <p class="admin-kicker">Narzędzia zaawansowane</p>
        <h1>Ustawienia monetyzacji</h1>
        <p>Globalne ustawienie liczby responsywnych miejsc reklamowych w artykułach. Reklamy są renderowane tylko w bezpiecznych miejscach między sekcjami.</p>
    </header>

    <?php if (isset($_GET['saved'])): ?>
        <p class="admin-notice is-success" role="status">Ustawienia monetyzacji zostały zapisane.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <form method="post" action="admin-monetization.php" class="admin-form admin-contact-form">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">
        <section class="admin-contact-setting">
            <h2>Liczba reklam względem liczby grafik</h2>
            <label for="ad-slot-offset">Mniej reklam <output id="ad-slot-offset-output" for="ad-slot-offset"><?php echo escape_html($offsetLabel); ?></output> Więcej reklam</label>
            <input class="admin-range" type="range" id="ad-slot-offset" name="ad_slot_offset" min="-2" max="2" step="1" value="<?php echo $offset; ?>" aria-describedby="ad-slot-offset-help">
            <div class="admin-range-labels" aria-hidden="true"><span>W−2</span><span>W−1</span><span>W</span><span>W+1</span><span>W+2</span></div>
            <p id="ad-slot-offset-help" class="admin-password-help">Liczba reklam jest automatycznie dopasowywana do liczby grafik w finalnym planie artykułu: od 2 do 6 slotów.</p>
            <p class="admin-password-help">Przykład: artykuł z 4 grafikami → <strong id="ad-slot-example"><?php echo $exampleCount; ?></strong> reklam.</p>
        </section>
        <ul class="actions special"><li><button type="submit">Zapisz ustawienia</button></li></ul>
    </form>
</section>
<script>
    (function () {
        var input = document.getElementById('ad-slot-offset');
        var output = document.getElementById('ad-slot-offset-output');
        var labels = {'-2': 'W−2', '-1': 'W−1', '0': 'W', '1': 'W+1', '2': 'W+2'};
        if (!input || !output) return;
        input.addEventListener('input', function () {
            output.textContent = labels[input.value] || 'W';
        });
    }());
</script>
<?php admin_page_close(); ?>
