# Category Change through Stage Transition in Workflows

This plugin allows administrators to assign a category to an article during a workflow transition in Joomla!.

## Features

1. **Category Selection in Transition Setup**  
    Administrators can select a category while configuring a workflow transition.

2. **Automatic Category Assignment**  
    Automatically assigns the selected category to an article when the transition is executed.

3. **Category Field Locking**  
    Locks the category selection field in the article editor when the plugin is active, ensuring the category is managed exclusively by the workflow transition.

## Implementation Details

- Built using the latest Joomla! plugin structure.
- Leverages Joomla! methods and APIs for compatibility and maintainability.

## Usage

1. Enable the plugin in the Joomla! Plugin Manager.
2. Configure the desired category in the workflow transition setup.
3. Execute the transition to automatically assign the category to the article.

## Dependencies

- Joomla! 4.x or later.

## Notes

- Ensure proper configuration to avoid conflicts with other extensions or customizations.
- The category locking feature is active only when the plugin is enabled.