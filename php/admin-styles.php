<?php

declare(strict_types=1);

require_once __DIR__ . '/admin-auth.php';
require_once __DIR__ . '/admin-database.php';
require_once __DIR__ . '/admin-ui.php';

require_admin_login();

$definitions = site_style_definitions();
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!admin_valid_csrf()) {
        $error = 'Sesja formularza wygasła. Odśwież stronę i spróbuj ponownie.';
    } else {
        $action = (string) ($_POST['action'] ?? 'save');

        try {
            if ($action === 'reset') {
                reset_site_style_settings();
                header('Location: admin-styles.php?reset=1', true, 303);
                exit;
            }

            $changes = [];
            foreach ($definitions as $key => $definition) {
                $changes[$key] = $definition['type'] === 'checkbox'
                    ? (isset($_POST[$key]) ? '1' : '0')
                    : (string) ($_POST[$key] ?? '');
            }

            update_site_style_settings($changes);
            header('Location: admin-styles.php?saved=1', true, 303);
            exit;
        } catch (Throwable $exception) {
            $error = $exception->getMessage();
        }
    }
}

$settings = get_site_style_settings();
$groups = [];
foreach ($definitions as $key => $definition) {
    $groups[(string) $definition['group']][$key] = $definition;
}

admin_page_open('Wygląd strony', 'styles');
?>
<section class="post admin-card">
    <header class="major admin-heading">
        <p class="admin-kicker">CMS</p>
        <h1>Wygląd strony</h1>
        <p>Zarządzaj wyglądem wyłącznie strony publicznej. Style i tło panelu administratora nie są przez te ustawienia zmieniane.</p>
    </header>

    <?php if (isset($_GET['saved'])): ?>
        <p class="admin-notice is-success" role="status">Wygląd strony został zapisany.</p>
    <?php elseif (isset($_GET['reset'])): ?>
        <p class="admin-notice is-success" role="status">Przywrócono neutralny motyw początkowy.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
        <p class="admin-notice is-error" role="alert"><?php echo escape_html($error); ?></p>
    <?php endif; ?>

    <form method="post" action="admin-styles.php" class="admin-form admin-contact-form">
        <input type="hidden" name="csrf" value="<?php echo escape_html(admin_csrf_token()); ?>">

        <?php foreach ($groups as $groupName => $fields): ?>
            <section class="admin-contact-setting">
                <h2><?php echo escape_html($groupName); ?></h2>

                <?php foreach ($fields as $key => $definition): ?>
                    <?php
                    $fieldId = 'style-' . str_replace('_', '-', $key);
                    $value = (string) ($settings[$key] ?? $definition['default']);
                    $type = (string) $definition['type'];
                    ?>
                    <label for="<?php echo escape_html($fieldId); ?>"><?php echo escape_html((string) $definition['label']); ?></label>

                    <?php if ($type === 'select'): ?>
                        <select id="<?php echo escape_html($fieldId); ?>" name="<?php echo escape_html($key); ?>">
                            <?php foreach ((array) $definition['options'] as $optionValue => $optionLabel): ?>
                                <option value="<?php echo escape_html((string) $optionValue); ?>"<?php echo $value === (string) $optionValue ? ' selected' : ''; ?>><?php echo escape_html((string) $optionLabel); ?></option>
                            <?php endforeach; ?>
                        </select>
                    <?php elseif ($type === 'number'): ?>
                        <input
                            type="number"
                            id="<?php echo escape_html($fieldId); ?>"
                            name="<?php echo escape_html($key); ?>"
                            value="<?php echo escape_html($value); ?>"
                            min="<?php echo escape_html((string) $definition['min']); ?>"
                            max="<?php echo escape_html((string) $definition['max']); ?>"
                            step="<?php echo escape_html((string) $definition['step']); ?>"
                            required
                        >
                    <?php elseif ($type === 'checkbox'): ?>
                        <label class="admin-social-visibility" for="<?php echo escape_html($fieldId); ?>">
                            <input type="checkbox" id="<?php echo escape_html($fieldId); ?>" name="<?php echo escape_html($key); ?>" value="1"<?php echo $value === '1' ? ' checked' : ''; ?>>
                            <span class="admin-toggle-track" aria-hidden="true"><span class="admin-toggle-knob"></span></span>
                            <span class="admin-toggle-label">Włączone na stronie publicznej</span>
                        </label>
                    <?php elseif ($type === 'textarea'): ?>
                        <textarea id="<?php echo escape_html($fieldId); ?>" name="<?php echo escape_html($key); ?>" rows="16" maxlength="<?php echo (int) $definition['max']; ?>" spellcheck="false" placeholder="/* Dodatkowe reguły CSS */"><?php echo escape_html($value); ?></textarea>
                    <?php else: ?>
                        <input
                            type="text"
                            id="<?php echo escape_html($fieldId); ?>"
                            name="<?php echo escape_html($key); ?>"
                            value="<?php echo escape_html($value); ?>"
                            maxlength="<?php echo (int) ($definition['max'] ?? 500); ?>"
                            <?php echo $type === 'color' ? 'pattern="#[0-9a-fA-F]{6}" placeholder="#000000" required' : ''; ?>
                            <?php echo $type === 'url' ? 'placeholder="/images/tlo.webp lub https://..."' : ''; ?>
                        >
                    <?php endif; ?>

                    <?php if (!empty($definition['help'])): ?>
                        <p class="admin-password-help"><?php echo escape_html((string) $definition['help']); ?></p>
                    <?php endif; ?>
                <?php endforeach; ?>
            </section>
        <?php endforeach; ?>

        <ul class="actions special">
            <li><button type="submit" name="action" value="save">Zapisz wygląd</button></li>
            <li><a class="button" href="../index.html" target="_blank" rel="noopener">Otwórz stronę</a></li>
            <li><button type="submit" name="action" value="reset" class="admin-danger-action" formnovalidate onclick="return window.confirm('Przywrócić neutralny motyw i usunąć własny CSS?');">Przywróć neutralny motyw</button></li>
        </ul>
    </form>
</section>
<?php admin_page_close(); ?>
