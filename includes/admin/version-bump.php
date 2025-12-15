<?php
if (!defined('ABSPATH')) {
    exit;
}

/**
 * Version bump tool: increments patch version in plugin header if plugin files changed.
 *
 * Behavior:
 * - Runs on admin_init (only for users with manage_options).
 * - Computes a hash of files' paths + modified timestamps (configurable extensions).
 * - If files hash changed compared to stored option lww_last_files_hash, it bumps patch version.
 * - Writes updated Version: header in LWW_PLUGIN_FILE (if writable).
 * - Updates options: lww_last_files_hash and lww_last_bumped_version.
 * - Adds admin_notice about action or errors.
 *
 * Filter to disable:
 * add_filter('lww_auto_bump_version_enabled', '__return_false');
 */

add_action('admin_init', 'lww_auto_bump_version_check');

function lww_auto_bump_version_enabled() {
    return apply_filters('lww_auto_bump_version_enabled', true);
}

/**
 * Computes a lightweight hash of files by scanning the plugin folder and concatenating file path + mtime.
 */
function lww_compute_files_hash($dir = LWW_PLUGIN_PATH, $exts = ['php','js','css','json','xml']) {
    $pieces = [];
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
    foreach ($it as $file) {
        if (!$file->isFile()) continue;
        $path = $file->getRealPath();
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        if (!in_array($extension, $exts, true)) continue;
        $pieces[] = $path . '|' . $file->getMTime() . '|' . $file->getSize();
    }
    sort($pieces);
    return md5(implode("\n", $pieces));
}

/**
 * Read the Version header value from plugin file.
 */
function lww_get_plugin_file_version($file = LWW_PLUGIN_FILE) {
    if (!file_exists($file)) return null;
    $contents = file_get_contents($file);
    if ($contents === false) return null;
    if (preg_match('/^.*Version:\s*([0-9]+(?:\.[0-9]+)*(?:\-[^\s]+)?).*$/mi', $contents, $m)) {
        return trim($m[1]);
    }
    return null;
}

/**
 * Increment the patch version number (semver-like): x.y.z -> x.y.(z+1)
 */
function lww_increment_patch_version($version) {
    if (!is_string($version) || $version === '') return '0.0.1';
    // Extract alfa portion if present (build/meta version)
    $partsMeta = preg_split('/\s+/', $version);
    $versionCore = $partsMeta[0];
    $parts = explode('.', $versionCore);
    while (count($parts) < 3) $parts[] = '0';
    $patch = (int)$parts[2];
    $patch++;
    $parts[2] = (string)$patch;
    return implode('.', array_slice($parts, 0, 3));
}

/**
 * Update plugin file's Version header with new version string.
 */
function lww_update_plugin_file_version($file, $newVersion) {
    if (!is_writable($file)) {
        return new WP_Error('file_not_writable', __('Plugin file not writable', 'lego-wawi'));
    }

    $contents = file_get_contents($file);
    if ($contents === false) {
        return new WP_Error('file_read_error', __('Unable to read plugin file', 'lego-wawi'));
    }

    if (preg_match('/(Version:\s*)([^\r\n]+)/mi', $contents, $m)) {
        $oldLine = $m[0];
        $newLine = $m[1] . $newVersion;
        $newContents = str_replace($oldLine, $newLine, $contents, $count = 1);
        if ($count === 0) {
            // fallback: try to replace any occurrence with better matching
            $newContents = preg_replace('/^(.*Version:\s*)([^\r\n]+)/mi', '${1}' . $newVersion, $contents, 1);
        }
    } else {
        // If no Version: header found, try to insert after the plugin header opening block
        $newContents = preg_replace('/(<\?php\s*\/\*\*[\s\S]*?)(\*\/)/mi', '${1}${2} ' . "\n" . ' * Version: ' . $newVersion . "\n", $contents, 1);
    }

    $writeResult = @file_put_contents($file, $newContents);
    if ($writeResult === false) {
        return new WP_Error('file_write_error', __('Unable to write plugin file', 'lego-wawi'));
    }

    return true;
}

/**
 * Main check: compute files hash, compare, bump plugin header version if changed.
 */
function lww_auto_bump_version_check() {
    // Only run for admins; prevents accidental writes by other contexts (cron, non-admin)
    if (!is_admin() || !current_user_can('manage_options')) {
        return;
    }

    if (!lww_auto_bump_version_enabled()) {
        return;
    }

    $option_hash = get_option('lww_last_files_hash', '');
    $hash = lww_compute_files_hash();

    if ($hash === $option_hash) {
        // nothing changed
        return;
    }

    // To avoid unexpected behaviors (e.g., during dev), require plugin file be writable
    if (!is_writable(LWW_PLUGIN_FILE)) {
        add_action('admin_notices', function() {
            echo '<div class="notice notice-warning is-dismissible"><p>' . esc_html__('Bricksberg WaWi: Plugin file is not writable – cannot auto-bump version. Please ensure webserver can write to plugin file or disable auto bump.', 'lego-wawi') . '</p></div>';
        });
        // Still update the stored hash so we don't repeatedly warn for same change
        update_option('lww_last_files_hash', $hash);
        return;
    }

    $currentVersion = lww_get_plugin_file_version();
    if ($currentVersion === null) {
        // No version found, skip
        add_action('admin_notices', function() {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Bricksberg WaWi: Could not detect plugin version in header; auto-bump skipped.', 'lego-wawi') . '</p></div>';
        });
        update_option('lww_last_files_hash', $hash);
        return;
    }

    $newVersion = lww_increment_patch_version($currentVersion);

    $res = lww_update_plugin_file_version(LWW_PLUGIN_FILE, $newVersion);
    if (is_wp_error($res)) {
        // Write failed – notify and update hash to avoid repeating
        add_action('admin_notices', function() use ($res) {
            echo '<div class="notice notice-error is-dismissible"><p>' . esc_html__('Bricksberg WaWi: Auto-bump failed: ', 'lego-wawi') . esc_html($res->get_error_message()) . '</p></div>';
        });
        update_option('lww_last_files_hash', $hash);
        return;
    }

    // Success: set last bumped info
    update_option('lww_last_files_hash', $hash);
    update_option('lww_last_bumped_version', $newVersion);
    update_option('lww_last_bumped_time', time());

    // Admin notice on next page render
    add_action('admin_notices', function() use ($currentVersion, $newVersion) {
        $msg = sprintf(__('Bricksberg WaWi: Version automatisch von %s auf %s erhöht.', 'lego-wawi'), esc_html($currentVersion), esc_html($newVersion));
        echo '<div class="notice notice-success is-dismissible"><p>' . $msg . '</p></div>';
    });
}
