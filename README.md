# Headless WP

This WordPress plugin is created to simplify and secure the integration of Single Page Applications (SPAs) with WordPress as a backend. It extends WordPress with useful REST API endpoints and configurable options to manage user authentication, registration, and user groups - ideal for headless WordPress setups.

## Features

- **App Host Configuration**  
  Define the host of your SPA. Used, for example, in activation emails sent to new users.

- **User Profile Images**  
  - Add a profile image to user profiles  
  - Enable users to upload their own profile image via REST API  
  - Optional: Allow only one image per user (old image is deleted when a new one is uploaded)  
  - Adds new API endpoint: `POST /user-image`

- **User Registration via REST API**  
  - Allow users to register via an API call  
  - Adds new API endpoint: `POST /users/register`

- **Password Reset Functionality**  
  - Allow users to request and confirm password resets via REST API  
  - Adds endpoints:  
    - `POST /reset-password/request`  
    - `POST /reset-password/confirm`

- **User Confirmation by Email**  
  - Require users to confirm their account via activation link sent by email  
  - Only after confirmation, users are assigned a role  
  - Adds endpoints:  
    - `POST /confirm-user`  
    - `POST /send-verification-email`  
  - Configurable options:  
    - **Confirmation Link Expiration**: Set expiration time (in minutes)  
    - **Confirmation Path**: Define the SPA path the activation link should redirect to

- **User Groups Support**  
  - Enable the creation and management of user groups  
  - Adds REST API endpoints for group management  
  - Configurable options:  
    - **Default Group Status**: Set newly created groups to public or private by default  
    - **Editable Group Status**: Allow group admins to change the group's visibility

## Use Case

This plugin is ideal for developers building SPAs (e.g., React, Vue, Angular apps) that rely on WordPress for user management and content delivery. It provides essential endpoints and customization options that are otherwise unavailable in a standard WordPress installation.

## Installation

1. Clone or download this repository.
2. Place the plugin folder into your WordPress `wp-content/plugins` directory.
3. Activate the plugin from the WordPress admin dashboard.
4. Configure the plugin settings under the new "SPA Settings" menu.

## License

MIT License

---

**Note**: Make sure your site is set up for sending emails (SMTP plugin or service), especially if you use features like account activation and password reset.
