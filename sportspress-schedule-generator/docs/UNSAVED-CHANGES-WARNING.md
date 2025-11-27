# Unsaved Changes Warning Feature

## Overview

The Schedule Generator now includes an unsaved changes warning system that prevents users from accidentally losing their work when navigating away from the configuration page.

## How It Works

### Detection
- Monitors all form inputs (text fields, selects, textareas, checkboxes, radio buttons)
- Compares current form state against the initial state when the page loaded
- Tracks changes in real-time using jQuery event listeners

### Warning Trigger
When a user tries to navigate away from the page (by clicking a link, closing the tab, or using browser back button) and there are unsaved changes, the browser displays a standard confirmation dialog:

> "You have unsaved changes. Are you sure you want to leave?"

### Reset Conditions
The warning flag is reset (no warning shown) when:
1. **Form is submitted** - User clicks "Save Configuration"
2. **Configuration is saved successfully** - AJAX save completes successfully
3. **Page is reloaded** - Fresh page load resets the tracking

## Implementation Details

### JavaScript Code Location
File: `sportspress-schedule-generator/includes/class-admin.php`

The code is added via `wp_add_inline_script()` in the `enqueue_admin_scripts()` method.

### Key Components

#### 1. Change Tracking
```javascript
var formChanged = false;
var initialFormData = $("#spsg-config-form").serialize();

$("#spsg-config-form").on("change input", "input, select, textarea", function() {
    var currentFormData = $("#spsg-config-form").serialize();
    formChanged = (currentFormData !== initialFormData);
});
```

#### 2. Browser Navigation Warning
```javascript
$(window).on("beforeunload", function(e) {
    if (formChanged) {
        var message = "You have unsaved changes. Are you sure you want to leave?";
        e.returnValue = message;
        return message;
    }
});
```

#### 3. Reset on Form Submit
```javascript
$("#spsg-config-form").on("submit", function() {
    formChanged = false;
});
```

#### 4. Reset on AJAX Save Success
```javascript
$(document).on("spsg-config-saved", function() {
    formChanged = false;
    initialFormData = $("#spsg-config-form").serialize();
});
```

The custom event `spsg-config-saved` is triggered in the AJAX save success handler:
```javascript
if (saveResponse.success) {
    $(document).trigger("spsg-config-saved");
    // ... show success message ...
}
```

## User Experience

### Scenario 1: User Makes Changes and Tries to Leave
1. User modifies any form field (e.g., changes division name)
2. User clicks browser back button or tries to close tab
3. Browser shows confirmation dialog
4. User can choose to stay or leave

### Scenario 2: User Saves Changes
1. User modifies form fields
2. User clicks "Save Configuration"
3. Configuration is saved successfully
4. Warning flag is reset
5. User can now navigate away without warning

### Scenario 3: User Makes Changes, Saves, Then Makes More Changes
1. User modifies form fields
2. User saves configuration (flag reset)
3. User makes additional changes
4. Warning is active again for new unsaved changes

## Browser Compatibility

The `beforeunload` event is supported by all modern browsers:
- Chrome/Edge: Shows generic message (browser-controlled text)
- Firefox: Shows generic message (browser-controlled text)
- Safari: Shows generic message (browser-controlled text)

**Note:** Modern browsers ignore custom messages in `beforeunload` for security reasons. The browser displays its own standard message, but the event still prevents navigation until the user confirms.

## Benefits

1. **Prevents Data Loss** - Users won't accidentally lose their work
2. **Better UX** - Clear feedback about unsaved changes
3. **Non-Intrusive** - Only warns when there are actual changes
4. **Standard Behavior** - Uses familiar browser confirmation dialog
5. **Automatic Reset** - No manual intervention needed after saving

## Testing Recommendations

### Test Case 1: Basic Warning
1. Load configuration page
2. Change any form field
3. Try to navigate away (back button, close tab, click link)
4. Verify warning appears

### Test Case 2: No Warning When No Changes
1. Load configuration page
2. Don't change anything
3. Try to navigate away
4. Verify no warning appears

### Test Case 3: Warning Reset After Save
1. Load configuration page
2. Change form field
3. Click "Save Configuration"
4. Wait for success message
5. Try to navigate away
6. Verify no warning appears

### Test Case 4: Warning After Save and New Changes
1. Load configuration page
2. Change form field
3. Save configuration
4. Make another change
5. Try to navigate away
6. Verify warning appears

### Test Case 5: All Input Types
Test with different input types:
- Text inputs (division names, venue names)
- Select dropdowns (Load from SportsPress)
- Textareas (team lists, blackout dates)
- Checkboxes (venue availability days)
- Number inputs (games per team)

## Future Enhancements

Potential improvements for future versions:

1. **Visual Indicator** - Add a visual indicator (e.g., asterisk or badge) showing unsaved changes
2. **Auto-Save** - Implement periodic auto-save functionality
3. **Change Summary** - Show which fields have been modified
4. **Undo/Redo** - Add ability to undo changes before saving
5. **Draft Mode** - Save changes as draft without validation

## Bypassing the Warning

Certain actions intentionally bypass the unsaved changes warning because they have their own confirmation dialogs:

### Actions That Bypass Warning

1. **Import League Structure** - Resets flag before navigation after successful import
2. **New Configuration** - Resets flag after user confirms in the "Create new" dialog
3. **Load Configuration** - Resets flag after user confirms in the "Load" dialog
4. **Delete Configuration** - Resets flag after user confirms deletion

### Implementation
```javascript
// Reset flag before navigation
formChanged = false;
window.location.href = targetUrl;
```

This prevents double warnings where the user sees both:
1. Custom confirmation dialog (e.g., "Create a new configuration? Any unsaved changes will be lost.")
2. Browser's beforeunload warning (e.g., "Changes you made may not be saved.")

## Troubleshooting

### Issue: Double Warning on Navigation
**Symptom:** User sees two confirmation dialogs when clicking "New" or "Load"

**Solution:** Ensure `formChanged = false;` is called before `window.location` navigation in all intentional navigation actions.

### Issue: Warning Not Appearing
**Symptom:** No warning shown when user has made changes

**Solution:** Check that:
1. Form has `id="spsg-config-form"`
2. jQuery is loaded
3. No JavaScript errors in console
4. Form fields are being monitored (input, select, textarea)

### Issue: Warning After Save
**Symptom:** Warning still appears after successful save

**Solution:** Ensure the `spsg-config-saved` event is triggered in the AJAX success handler.

## Related Files

- `sportspress-schedule-generator/includes/class-admin.php` - Main implementation
- `sportspress-schedule-generator/assets/js/schedule-generator.js` - Additional JavaScript functionality

## Notes

- The warning only applies to the Schedule Generator configuration page
- The warning does not prevent form submission (intentional - user wants to save)
- The serialized form data comparison is efficient and works with all form field types
- Select2 changes are properly detected through the underlying select element
- Actions with their own confirmation dialogs bypass the beforeunload warning to prevent double prompts
