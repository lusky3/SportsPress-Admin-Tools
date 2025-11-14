<?php
/**
 * Events Import Preview Template
 */
if (!defined('ABSPATH')) exit;
?>

<h2><?php _e('Import Preview', 'sportspress-admin-tools'); ?></h2>

<div class="card">
    <h3><?php _e('Team Mappings', 'sportspress-admin-tools'); ?></h3>
    <p><?php _e('Review and adjust team matches. Auto-matched teams are pre-selected.', 'sportspress-admin-tools'); ?></p>
    
    <form method="post">
        <?php wp_nonce_field('confirm_import'); ?>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Schedule Team Name', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Match to Existing Team', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Auto-Match Result', 'sportspress-admin-tools'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unique_teams as $team_name => $match): ?>
                <tr>
                    <td><strong><?php echo esc_html($team_name); ?></strong></td>
                    <td>
                        <select name="team_mappings[<?php echo esc_attr($team_name); ?>]" required>
                            <option value=""><?php _e('Select team...', 'sportspress-admin-tools'); ?></option>
                            <?php foreach ($existing_teams as $id => $name): ?>
                                <option value="<?php echo $id; ?>" <?php selected($match && $match['id'] == $id); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php if ($match): ?>
                            <span style="color: #00a32a;"><?php echo esc_html($match['name']); ?> (<?php echo $match['type']; ?>)</span>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('No match found', 'sportspress-admin-tools'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (!empty($unique_leagues)): ?>
        <h3><?php _e('League Mappings', 'sportspress-admin-tools'); ?></h3>
        <p><?php printf(__('Review and adjust %s matches. Auto-matched %s are pre-selected.', 'sportspress-admin-tools'), strtolower(SPAT_Text_Helper::get_text('League')), strtolower(SPAT_Text_Helper::get_text('Leagues'))); ?></p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Schedule League Name', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Match to Existing League', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Auto-Match Result', 'sportspress-admin-tools'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($unique_leagues as $league_name => $match): ?>
                <tr>
                    <td><strong><?php echo esc_html($league_name); ?></strong></td>
                    <td>
                        <select name="league_mappings[<?php echo esc_attr($league_name); ?>]">
                            <option value=""><?php printf(__('No %s', 'sportspress-admin-tools'), strtolower(SPAT_Text_Helper::get_text('League'))); ?></option>
                            <?php foreach ($existing_leagues as $id => $name): ?>
                                <option value="<?php echo $id; ?>" <?php selected($match && $match['id'] == $id); ?>><?php echo esc_html($name); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <?php if ($match): ?>
                            <span style="color: #00a32a;"><?php echo esc_html($match['name']); ?> (<?php echo $match['type']; ?>)</span>
                        <?php else: ?>
                            <span style="color: #d63638;"><?php _e('No match found', 'sportspress-admin-tools'); ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <h3><?php _e('Import Settings', 'sportspress-admin-tools'); ?></h3>
        <table class="form-table">
            <tr>
                <th scope="row"><?php _e('Season', 'sportspress-admin-tools'); ?></th>
                <td>
                    <select name="import_season" required style="width: 200px;">
                        <option value=""><?php _e('Select season...', 'sportspress-admin-tools'); ?></option>
                        <?php 
                        $seasons = get_terms(array('taxonomy' => 'sp_season', 'hide_empty' => false));
                        foreach ($seasons as $season): ?>
                            <option value="<?php echo $season->term_id; ?>"><?php echo esc_html($season->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <p class="description"><?php _e('All imported events will be assigned to this season.', 'sportspress-admin-tools'); ?></p>
                </td>
            </tr>
        </table>
        
        <h3><?php printf(__('%s to Import', 'sportspress-admin-tools'), SPAT_Text_Helper::get_text('Events')); ?></h3>
        <p><?php printf(__('Found %d events using the team and %s mappings above.', 'sportspress-admin-tools'), count($events), strtolower(SPAT_Text_Helper::get_text('League'))); ?></p>
        
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th><?php _e('Date', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Time', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Home Team', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('Away Team', 'sportspress-admin-tools'); ?></th>
                    <th><?php _e('League', 'sportspress-admin-tools'); ?></th>
                    <th><?php SPAT_Text_Helper::echo_text('Venue'); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($events as $event): ?>
                <tr>
                    <td><?php echo esc_html($event['date']); ?></td>
                    <td><?php echo esc_html($event['time']); ?></td>
                    <td><?php echo esc_html($event['home_team']); ?></td>
                    <td><?php echo esc_html($event['away_team']); ?></td>
                    <td><?php echo esc_html($event['league']); ?></td>
                    <td><?php echo esc_html($event['venue']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <p class="submit">
            <?php submit_button(__('Confirm Import', 'sportspress-admin-tools'), 'primary', 'confirm_import', false); ?>
            <a href="<?php echo admin_url('admin.php?page=sportspress-admin-tools&tab=events'); ?>" class="button"><?php _e('Cancel', 'sportspress-admin-tools'); ?></a>
        </p>
    </form>
</div>

<style>
.card { max-width: none !important; }
.wp-list-table th, .wp-list-table td { padding: 8px 6px; }
.wp-list-table select { min-width: 200px; width: 100%; }
.wp-list-table th:nth-child(2), .wp-list-table td:nth-child(2) { width: 250px; }
</style>

<?php if (get_option('spat_use_select2', '0')): ?>
<script>
jQuery(document).ready(function($) {
    if (typeof $.fn.select2 !== 'undefined') {
        $('.wp-list-table select, select[name="import_season"]').select2({
            width: '100%',
            placeholder: 'Select an option...',
            allowClear: false
        });
    }
});
</script>
<?php endif; ?>