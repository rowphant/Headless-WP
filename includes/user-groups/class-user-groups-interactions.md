### User Groups Interactions

*   **HWP\_User\_Groups\_Interactions Class**:
    
    *   This new class extends HWP\_User\_Groups\_Base to leverage existing functionalities like post\_type and user\_groups\_get\_custom\_fields.
        
    *   It now registers all new API endpoints and their respective callback functions.
        
*   **Group Request Endpoints**:
    
    *   **`hwp/v1/group-requests` (POST)**: Allows a logged-in user to send a request to join a group.
        
    *   **`hwp/v1/group-requests/(?P\\d+)` (DELETE)**: Allows a logged-in user to withdraw their request or an admin to reject/delete a request.
        
*   **Group Invitation Endpoints**:
    
    *   **`hwp/v1/group-invitations/send` (POST)**: Allows a group admin or author to create a new invitation for a user.
        
    *   **`hwp/v1/group-invitations/delete/(?P\\d+)/(?P\[^/\]+)` (POST)**: Allows a group admin or author to delete an invitation.
        
    *   **`hwp/v1/group-invitations/(?P(accept|decline))` (POST)**: Modified to reflect the changes in the new class.
        
*   **Permissions**:
    
    *   For **group requests**, the API endpoints require the user to be logged in (is\_user\_logged\_in).
        
    *   For **group invitations**, only group\_admin or group\_author can send/delete invitations. I've included placeholder checks for these roles, which you'll need to implement based on your HWP\_User\_Groups\_Base or other role management.
        
*   **Storing Declined Requests**:
    
    *   When an admin rejects a group request, the group ID is saved to the user's meta with the key group\_declined\_requests.
        
*   **Error Handling and Responses**: Consistent WP\_Error and WP\_REST\_Response handling for better API feedback.