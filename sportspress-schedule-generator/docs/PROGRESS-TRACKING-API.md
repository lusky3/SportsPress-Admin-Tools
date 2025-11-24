# Progress Tracking API Documentation

## Overview

The schedule generation engine now includes comprehensive progress tracking, cancellation support, and enhanced error handling. This document describes the API for integrating with the progress tracking system.

## Public Methods

### SPSG_Schedule_Engine

#### `get_progress()`

Retrieves the current generation progress.

**Returns:** `array|false`
- Returns progress data array if generation is in progress
- Returns `false` if no generation is in progress

**Progress Data Structure:**
```php
array(
    'phase' => string,              // Current phase: 'starting', 'validation', 'matchups', 'allocation', 'complete'
    'percentage' => int,            // Completion percentage (0-100)
    'message' => string,            // User-friendly status message
    'games_scheduled' => int,       // Number of games scheduled so far
    'total_games' => int,           // Total games to schedule
    'start_time' => float,          // Microtime when generation started
    'estimated_time_remaining' => float|null,  // Estimated seconds remaining
    'cancelled' => bool             // Whether generation has been cancelled
)
```

**Example:**
```php
$engine = new SPSG_Schedule_Engine();
$progress = $engine->get_progress();

if ($progress !== false) {
    echo "Phase: {$progress['phase']}\n";
    echo "Progress: {$progress['percentage']}%\n";
    echo "Status: {$progress['message']}\n";
    
    if ($progress['estimated_time_remaining'] !== null) {
        echo "ETA: " . round($progress['estimated_time_remaining']) . " seconds\n";
    }
}
```

#### `cancel_generation()`

Cancels an in-progress generation.

**Returns:** `void`

**Example:**
```php
$engine = new SPSG_Schedule_Engine();
$engine->cancel_generation();
```

**Notes:**
- Sets the cancellation flag in the progress transient
- The generation will stop at the next cancellation check point
- Partial results will be saved before returning

#### `generate_schedule($config)`

Generates a complete schedule with progress tracking.

**Parameters:**
- `$config` (SPSG_Schedule_Configuration): Schedule configuration

**Returns:** `array|WP_Error`
- On success: `array('schedule' => array, 'stats' => array)`
- On failure: `WP_Error` with error code and data

**Error Codes:**
- `configuration_error`: Configuration validation failed
- `generation_cancelled`: User cancelled generation
- `generation_timeout`: Generation exceeded time limit
- `allocation_failed`: Could not allocate all games
- `infeasible_config`: Configuration is not feasible

**Example:**
```php
$engine = new SPSG_Schedule_Engine();
$result = $engine->generate_schedule($config);

if (is_wp_error($result)) {
    $code = $result->get_error_code();
    $message = $result->get_error_message();
    $data = $result->get_error_data();
    
    if ($code === 'configuration_error') {
        // Show configuration issues and suggestions
        $issues = $data['issues'];
        $suggestions = $data['suggestions'];
    } elseif ($code === 'generation_cancelled') {
        // Show partial results
        $partial_schedule = $data['partial_schedule'];
    }
} else {
    $schedule = $result['schedule'];
    $stats = $result['stats'];
}
```

## Progress Phases

The generation process goes through the following phases:

1. **starting** (0%): Initializing generation
2. **validation** (0-5%): Validating configuration
3. **matchups** (5-10%): Generating team matchups
4. **allocation** (10-90%): Allocating games to time slots
5. **validation** (90-100%): Handling makeup games
6. **complete** (100%): Generation complete

## AJAX Integration Example

### Start Generation

```php
add_action('wp_ajax_spsg_generate_schedule', 'spsg_ajax_generate_schedule');

function spsg_ajax_generate_schedule() {
    check_ajax_referer('spsg_generate_schedule', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    // Load configuration
    $config_id = intval($_POST['config_id']);
    $config = SPSG_Configuration_Manager::get_instance()->get_configuration($config_id);
    
    if (!$config) {
        wp_send_json_error('Configuration not found');
    }
    
    // Start generation
    $engine = new SPSG_Schedule_Engine();
    $result = $engine->generate_schedule($config);
    
    if (is_wp_error($result)) {
        wp_send_json_error(array(
            'code' => $result->get_error_code(),
            'message' => $result->get_error_message(),
            'data' => $result->get_error_data()
        ));
    } else {
        // Store schedule in transient for preview
        $schedule_id = uniqid('schedule_');
        set_transient('spsg_schedule_' . $schedule_id, $result['schedule'], HOUR_IN_SECONDS);
        
        wp_send_json_success(array(
            'schedule_id' => $schedule_id,
            'stats' => $result['stats']
        ));
    }
}
```

### Poll for Progress

```php
add_action('wp_ajax_spsg_get_generation_progress', 'spsg_ajax_get_progress');

function spsg_ajax_get_progress() {
    check_ajax_referer('spsg_get_progress', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $engine = new SPSG_Schedule_Engine();
    $progress = $engine->get_progress();
    
    if ($progress === false) {
        wp_send_json_error('No generation in progress');
    } else {
        wp_send_json_success($progress);
    }
}
```

### Cancel Generation

```php
add_action('wp_ajax_spsg_cancel_generation', 'spsg_ajax_cancel_generation');

function spsg_ajax_cancel_generation() {
    check_ajax_referer('spsg_cancel_generation', 'nonce');
    
    if (!current_user_can('manage_options')) {
        wp_send_json_error('Insufficient permissions');
    }
    
    $engine = new SPSG_Schedule_Engine();
    $engine->cancel_generation();
    
    wp_send_json_success('Generation cancelled');
}
```

## JavaScript Integration Example

```javascript
// Start generation
function startGeneration(configId) {
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'spsg_generate_schedule',
            nonce: spsg_nonce,
            config_id: configId
        },
        success: function(response) {
            if (response.success) {
                console.log('Generation complete:', response.data);
            } else {
                console.error('Generation failed:', response.data);
            }
        }
    });
    
    // Start polling for progress
    pollProgress();
}

// Poll for progress
function pollProgress() {
    const interval = setInterval(function() {
        jQuery.ajax({
            url: ajaxurl,
            type: 'POST',
            data: {
                action: 'spsg_get_generation_progress',
                nonce: spsg_progress_nonce
            },
            success: function(response) {
                if (response.success) {
                    const progress = response.data;
                    
                    // Update UI
                    updateProgressBar(progress.percentage);
                    updateStatusMessage(progress.message);
                    
                    if (progress.estimated_time_remaining !== null) {
                        updateETA(progress.estimated_time_remaining);
                    }
                    
                    // Stop polling if complete or cancelled
                    if (progress.phase === 'complete' || progress.cancelled) {
                        clearInterval(interval);
                    }
                } else {
                    // No generation in progress
                    clearInterval(interval);
                }
            }
        });
    }, 2000); // Poll every 2 seconds
}

// Cancel generation
function cancelGeneration() {
    jQuery.ajax({
        url: ajaxurl,
        type: 'POST',
        data: {
            action: 'spsg_cancel_generation',
            nonce: spsg_cancel_nonce
        },
        success: function(response) {
            if (response.success) {
                console.log('Generation cancelled');
            }
        }
    });
}
```

## Error Handling

### Configuration Errors

Configuration errors include actionable suggestions:

```php
if (is_wp_error($result) && $result->get_error_code() === 'configuration_error') {
    $data = $result->get_error_data();
    
    echo '<h3>Configuration Issues:</h3>';
    echo '<ul>';
    foreach ($data['issues'] as $issue) {
        echo '<li>' . esc_html($issue) . '</li>';
    }
    echo '</ul>';
    
    echo '<h3>Suggestions:</h3>';
    echo '<ul>';
    foreach ($data['suggestions'] as $suggestion) {
        echo '<li>' . esc_html($suggestion) . '</li>';
    }
    echo '</ul>';
}
```

### Cancellation

When generation is cancelled, partial results are available:

```php
if (is_wp_error($result) && $result->get_error_code() === 'generation_cancelled') {
    $data = $result->get_error_data();
    
    echo 'Generation cancelled after scheduling ' . $data['games_scheduled'] . ' of ' . $data['total_games'] . ' games.';
    
    // Optionally use partial schedule
    $partial_schedule = $data['partial_schedule'];
}
```

### Timeout

When generation times out, partial results are saved:

```php
if (is_wp_error($result) && $result->get_error_code() === 'generation_timeout') {
    $data = $result->get_error_data();
    
    echo 'Generation timed out after ' . round($data['elapsed_time']) . ' seconds.';
    echo 'Scheduled ' . $data['games_scheduled'] . ' of ' . count($matchups) . ' games.';
    
    // Optionally use partial schedule
    $partial_schedule = $data['partial_schedule'];
}
```

## Transient Management

Progress is stored in a user-specific transient:

- **Key format:** `spsg_generation_progress_{user_id}`
- **Expiration:** 1 hour (HOUR_IN_SECONDS)
- **Auto-cleanup:** Transient is deleted on completion, cancellation, or error

## Performance Considerations

- Progress updates occur every 10 games to minimize transient writes
- Cancellation checks are lightweight (simple boolean check)
- Timeout checks use microtime comparison (very fast)
- Estimated time remaining is calculated only when progress > 0%

## Thread Safety

The progress tracking system is designed for single-user generation:

- Each user has their own progress transient
- Multiple users can generate schedules simultaneously
- Concurrent generations by the same user will overwrite progress

## Best Practices

1. **Poll frequency:** Poll for progress every 2-3 seconds
2. **Timeout handling:** Set a reasonable max generation time (5 minutes default)
3. **User feedback:** Always show progress percentage and status message
4. **Error display:** Show both error message and suggestions
5. **Cancellation:** Provide a cancel button during generation
6. **Cleanup:** Clear progress transient after displaying results

## Debugging

Enable debug logging to see detailed progress information:

```php
update_option('spsg_enable_debug_logging', '1');
```

Log messages will appear in the WordPress debug log with the prefix `[SPSG Engine]`.
