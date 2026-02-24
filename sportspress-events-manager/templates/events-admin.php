<?php
/**
 * Events Management Admin Interface Template
 */
if (!defined('ABSPATH')) {
    exit;
}
?>

<h2><?php _e('Calendar Management', 'sportspress-admin-tools'); ?></h2>

<div class="card">
    <h3><?php _e('Reset Calendars to Current Season', 'sportspress-admin-tools'); ?></h3>
    <p><?php _e('Update all existing calendars to use the current season.', 'sportspress-admin-tools'); ?></p>
    
    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('This will update all calendars to the current season. Continue?', 'sportspress-admin-tools')); ?>')">
        <?php wp_nonce_field('reset_calendars'); ?>
        <?php submit_button(__('Reset All Calendars', 'sportspress-admin-tools'), 'secondary', 'reset_calendars'); ?>
    </form>
</div>

<div class="card">
    <h3><?php _e('Auto-Create Calendars', 'sportspress-admin-tools'); ?></h3>
    <p><?php _e('Calendars are automatically created when new teams are added based on the settings above.', 'sportspress-admin-tools'); ?></p>
    <?php
    $auto_create_enabled = get_option('spat_events_auto_calendar_creation', '1');
    $status_color = $auto_create_enabled ? '#00a32a' : '#d63638';
    $status_text = $auto_create_enabled ? __('Enabled', 'sportspress-admin-tools') : __('Disabled', 'sportspress-admin-tools');
    ?>
    <p><strong><?php _e('Status:', 'sportspress-admin-tools'); ?></strong> <span style="color: <?php echo $status_color; ?>;"><?php echo $status_text; ?></span></p>
    
    <form method="post" onsubmit="return confirm('<?php echo esc_js(__('Create calendars for all teams that don\'t have one? This will use the current naming settings.', 'sportspress-admin-tools')); ?>')">
        <?php wp_nonce_field('create_missing_calendars'); ?>
        <p><?php _e('For existing teams without calendars:', 'sportspress-admin-tools'); ?></p>
        <?php submit_button(__('Create Missing Calendars', 'sportspress-admin-tools'), 'secondary', 'create_missing_calendars'); ?>
    </form>
</div>

<h2><?php printf(__('%s Import', 'sportspress-admin-tools'), SPAT_Text_Helper::get_text('Event')); ?></h2>

<div class="card">
    <h3><?php printf(__('Import %s from XLSX', 'sportspress-admin-tools'), SPAT_Text_Helper::get_text('Events')); ?></h3>
    <p><?php _e('Upload an Excel file (.xlsx) to import multiple events at once.', 'sportspress-admin-tools'); ?></p>
    
    <form method="post" enctype="multipart/form-data">
        <?php wp_nonce_field('import_events'); ?>
        
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('XLSX File', 'sportspress-admin-tools'); ?></th>
                <td>
                    <input type="file" name="events_xlsx" accept=".xlsx" required>
                    <p class="description">
                        <?php printf(__('Expected columns: Date, Time, Home Team, Away Team, %s (optional), %s (optional)', 'sportspress-admin-tools'), SPAT_Text_Helper::get_text('Venue'), SPAT_Text_Helper::get_text('League')); ?>
                    </p>
                </td>
            </tr>
        </table>
        
        <?php submit_button(sprintf(__('Import %s', 'sportspress-admin-tools'), SPAT_Text_Helper::get_text('Events')), 'primary', 'import_events'); ?>
    </form>
    
    <h4><?php _e('File Format Requirements', 'sportspress-admin-tools'); ?></h4>
    <ul>
        <li><?php _e('First row must contain column headers', 'sportspress-admin-tools'); ?></li>
        <li><?php _e('Required columns: Date, Home Team, Away Team', 'sportspress-admin-tools'); ?></li>
        <li><?php printf(__('Optional columns: Time, %s, %s/Division', 'sportspress-admin-tools'), SPAT_Text_Helper::get_text('Venue'), SPAT_Text_Helper::get_text('League')); ?></li>
        <li><?php _e('Date formats: Multiple formats supported (YYYY-MM-DD, MM/DD/YYYY, DD/MM/YYYY, etc.)', 'sportspress-admin-tools'); ?></li>
        <li><?php _e('Time formats: Multiple formats supported (HH:MM, H:MM AM/PM, etc.)', 'sportspress-admin-tools'); ?></li>
    </ul>
    
    <h4><?php _e('Smart Import Features', 'sportspress-admin-tools'); ?></h4>
    <ul>
        <li><?php _e('Automatic team name cleaning (removes numbers, extra spaces)', 'sportspress-admin-tools'); ?></li>
        <li><?php _e('Fuzzy team matching (finds similar existing teams)', 'sportspress-admin-tools'); ?></li>
        <li><?php _e('Flexible column name recognition', 'sportspress-admin-tools'); ?></li>
        <li><?php _e('Handles inconsistent formatting automatically', 'sportspress-admin-tools'); ?></li>
    </ul>
</div>
