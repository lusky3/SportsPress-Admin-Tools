# SportsPress Import Guide

## Two Ways to Import from SportsPress

The Schedule Generator provides two different methods for importing teams from SportsPress, each designed for different use cases.

### 1. Import League (Bulk Import)

**Location:** Top of the "Divisions & Teams" tab

**What it does:**
- Imports the entire league structure at once
- Creates multiple division blocks automatically
- Each child division in SportsPress becomes a separate division block
- All teams are imported with their division assignments

**When to use:**
- You have a complete league structure in SportsPress with multiple divisions
- You want to quickly import everything at once
- Your SportsPress league has child divisions (hierarchical structure)

**How it works:**
1. Select a SportsPress league from the dropdown
2. Click "Import League Structure"
3. Multiple division blocks are created automatically
4. Each division is populated with its teams from SportsPress

### 2. Load from SportsPress (Individual Division)

**Location:** Within each division block

**What it does:**
- Loads teams from a single SportsPress league/division
- Populates only the current division block
- Gives you control over which teams go into which division

**When to use:**
- You want to manually control division assignments
- You're building divisions one at a time
- You want to mix teams from different SportsPress leagues
- You want to add teams to an existing division

**How it works:**
1. Add or select a division block
2. Choose a SportsPress league/division from the dropdown
3. Click "Load Teams"
4. Teams from that league are added to this division only

## Understanding SportsPress League Hierarchy

SportsPress uses a hierarchical taxonomy structure for leagues:

```
Parent League (e.g., "2024 Season")
├── Child Division 1 (e.g., "Division A")
│   ├── Team 1
│   ├── Team 2
│   └── Team 3
└── Child Division 2 (e.g., "Division B")
    ├── Team 4
    ├── Team 5
    └── Team 6
```

### Why Both Parent and Child Leagues Appear

The dropdown shows ALL leagues (both parent and child) because:
- Some leagues don't have a hierarchical structure
- You might want to import from a parent league (imports all child divisions)
- You might want to import from a specific child division only

**Tip:** If you see duplicate-looking entries, check if one is a parent league and others are child divisions.

## Comparison Table

| Feature | Import League | Load from SportsPress |
|---------|--------------|----------------------|
| **Scope** | Entire league structure | Single division |
| **Division Blocks** | Creates multiple automatically | Populates current block only |
| **Speed** | Fast for bulk import | Better for precise control |
| **Use Case** | Complete league setup | Custom division building |
| **Teams** | All teams from all child divisions | Teams from selected league only |

## Best Practices

### For Complete League Import
1. Use "Import League" at the top
2. Select your parent league
3. Review the created divisions
4. Adjust team assignments if needed

### For Custom Setup
1. Add division blocks manually
2. Use "Load from SportsPress" in each block
3. Select specific leagues/divisions for each
4. Mix and match as needed

### For Mixed Approach
1. Import a base league structure
2. Add additional divisions manually
3. Use "Load from SportsPress" to add more teams
4. Customize as needed

## Troubleshooting

### "Import League shows child leagues but not parent"
- **Answer:** The dropdown shows ALL leagues. Parent leagues may not have teams directly assigned - teams are usually in child divisions. When you import a parent league, it imports all child divisions.

### "New division has pre-filled values"
- **Answer:** This was a bug that has been fixed. New divisions now start empty.

### "Teams appear in wrong division"
- **Answer:** Use "Load from SportsPress" within each division block for precise control over team placement.

### "Can't find my league"
- **Answer:** Ensure:
  - SportsPress is active
  - Leagues are published
  - Teams are assigned to leagues
  - You have the correct permissions

## Technical Details

### Import League Process
1. Fetches league structure from SportsPress
2. Identifies all child divisions
3. Creates a division block for each child
4. Populates each block with its teams
5. Preserves team metadata

### Load from SportsPress Process
1. Fetches teams from selected league
2. Adds teams to current division block
3. Preserves existing teams in the block
4. Updates team list display

## Related Documentation

- [Configuration Guide](CONFIGURATION-GUIDE.md)
- [SportsPress Integration](SPORTSPRESS-INTEGRATION.md)
- [Division Management](DIVISION-MANAGEMENT.md)
