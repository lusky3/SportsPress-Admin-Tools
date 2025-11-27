# Tooltips and Help Text Implementation

## Overview

Tooltips and help text have been implemented throughout the Schedule Generator UI to provide contextual assistance to users.

## Implementation Details

### CSS Styles
- Added comprehensive tooltip styles in `assets/css/admin.css`
- Tooltips are positioned above elements by default
- Support for left, right positioning variants
- Mobile-responsive with touch support
- Accessible with keyboard focus indicators

### JavaScript Module
- Created `Tooltips` module in `assets/js/schedule-generator.js`
- Automatically initializes all tooltips on page load
- Adds ARIA attributes for screen reader compatibility
- Keyboard accessible (Tab, Enter, Space, Escape)
- Touch-friendly for mobile devices

### Accessibility Features

1. **Keyboard Navigation**
   - Tooltips can be focused with Tab key
   - Show/hide with Enter or Space
   - Close with Escape key

2. **Screen Reader Support**
   - ARIA `role="tooltip"` attribute
   - `aria-describedby` linking tooltip to trigger
   - Unique IDs for each tooltip

3. **Mobile Support**
   - Touch to toggle tooltip visibility
   - Tap outside to close
   - Responsive sizing for small screens

## Usage

### HTML Structure
```html
<span class="spsg-tooltip">
    <span class="spsg-tooltip-icon" tabindex="0">?</span>
    <span class="spsg-tooltip-text">
        Your helpful tooltip text here
    </span>
</span>
```

### Positioning Variants
- Default (top): `<span class="spsg-tooltip">`
- Right: `<span class="spsg-tooltip spsg-tooltip-right">`
- Left: `<span class="spsg-tooltip spsg-tooltip-left">`

## Existing Help Text

The following areas already have comprehensive help text:

### Import Dialog
- Conflict resolution options with detailed descriptions
- Event status selection with guidance
- League and season selection explanations
- Dry run mode description
- All options have `aria-describedby` attributes

### Export Filters
- Division filter with description
- Date range filters with explanations
- Filtered game count display
- All filters have description paragraphs

### Configuration Options
- Preset selector with detailed description
- Playing days with explanations
- Time slots with format guidance
- All major configuration fields have help text

### Statistics Panel
- Summary statistics with labels
- Detailed tables with clear headers
- Color-coded balance indicators
- Imbalance warnings with severity levels

## Testing

### Manual Testing Checklist
- [x] Tooltips display on hover
- [x] Tooltips display on keyboard focus
- [x] Tooltips close with Escape key
- [x] Tooltips work on mobile (touch)
- [x] Screen reader announces tooltip content
- [x] Color contrast meets WCAG 2.1 AA standards
- [x] Responsive on all screen sizes

### Browser Compatibility
- Chrome (latest)
- Firefox (latest)
- Safari (latest)
- Edge (latest)
- Mobile browsers (iOS Safari, Chrome Mobile)

## Future Enhancements

If additional tooltips are needed:

1. Add HTML structure with `spsg-tooltip` class
2. Include `spsg-tooltip-icon` with tabindex="0"
3. Add `spsg-tooltip-text` with helpful content
4. Tooltips will automatically be initialized
5. ARIA attributes will be added automatically

## Notes

- Tooltips use WordPress admin color scheme
- Consistent with WordPress UI patterns
- Minimal JavaScript for performance
- Progressive enhancement approach
- Works without JavaScript (description text still visible)
