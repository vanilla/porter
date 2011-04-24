<?php
/**
 * WBB3 exporter tool
 *
 * @author Lieuwe Jan Eilander (lieuwejan.com)
 *
 * Framework:
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 *
 * Notice:
 * WBB3 uses two different table prefixes, so the prefix given will 
 * not be used.
 *
 * Tested with WBB v. 3.0.9
 */

class WBB3 extends ExportController {

  /** @var array Required tables => columns */
  protected $SourceTables = array(
    'wbb1_1_board' => array('boardID', 'parentID', 'title', 'description', 'time', 'threads', 'posts'),
    'wbb1_1_board_structure' => array('boardID', 'parentID', 'position'),
    'wbb1_1_post' => array('postID', 'threadID', 'userID', 'username', 'message', 'time', 'editorID', 'lastEditTime', 'deleteTime', 'deletedByID'),
    'wbb1_1_thread' => array('threadID', 'boardID', 'topic', 'firstPostID', 'time', 'userID', 'lastPostTime', 'lastPosterID', 'replies', 'views', 'isAnnouncement', 'isClosed', 'deleteTime', 'deletedByID'),
    'wcf1_user' => array('userID', 'username', 'email', 'password', 'salt', 'registrationDate', 'userTitle', 'lastActivityTime'),
    'wcf1_group' => array('groupID', 'groupName', 'groupDescription'), 
    'wcf1_user_to_groups' => array('userID', 'groupID'),
  );

  /** 
   * Forum-specific export format
   * @param ExportModel $Ex
   */
  protected function ForumExport($Ex)
  {
    // Begin
    $Ex->BeginExport('', 'WBB 3.x', array('HashMethod' => 'Vanilla'));
  
    // Users
    $User_Map = array(
      'userID' => 'UserID',
      'username' => 'Name',
      'email' => 'Email',
      'password2' => 'Password',
    );
    $Ex->ExportTable('User', 'select *,
      concat(`password`, salt) as password2,
      FROM_UNIXTIME(nullif(registrationDate, 0)) as DateInserted,
      FROM_UNIXTIME(nullif(registrationDate, 0)) as DateFirstVisit,
      FROM_UNIXTIME(nullif(lastActivityTime, 0)) as DateLastActive,
      FROM_UNIXTIME(nullif(lastActivityTime, 0)) as DateUpdated
      from wcf1_user', $User_Map);

    // Role
    $Role_Map = array(
      'groupID' => 'RoleID',
      'groupName' => 'Name',
      'groupDescription' => 'Description',
    );
    $Ex->ExportTable('Role', 'select * from wcf1_group', $Role_Map);

    // UserRole
    $UserRole_Map = array(
      'userID' => 'UserID',
      'groupID' => 'RoleID',
    );
    $Ex->ExportTable('UserRole', 'select * from wcf1_user_to_groups', $UserRole_Map);

    // Categories
    $Category_Map = array(
      'boardID' => 'CategoryID',
      'posts' => 'CountComments',
      'threads' => 'CountDiscussions',
      'title' => 'Name',
      'description' => 'Description',
    );
    $Ex->ExportTable('Category', 'select *,
      FROM_UNIXTIME(time) as DateInserted,
      FROM_UNIXTIME(time) as DateUpdated,
      nullif(parentID, 0) as ParentCategoryID,
      s.position AS Sort
      from wbb1_1_board b
      left join wbb1_1_board_structure s 
        ON (b.boardID = s.boardID AND ParentCategoryID = s.parentID)', $Category_Map, $Category_Map);
    
    // Discussions
    $Discussion_Map = array(
      'threadID' => 'DiscussionID',
      'boardID' => 'CategoryID',
      'userID' => 'InsertUserID',
      'topic' => 'Name',
      'isAnnouncement' => 'Announce',
      'isClosed' => 'Closed',
      'views' => 'CountViews',
      'lastPosterID' => 'LastCommentUserID',
    );
    $Ex->ExportTable('Discussion', 'select *,
      p.message as Body,
      replies+1 as CountComments,
      FROM_UNIXTIME(lastPostTime) as DateLastComment, 
      FROM_UNIXTIME(lastPostTime) as DateUpdated,
      FROM_UNIXTIME(time) as DateInserted,
      \'BBCode\' as Format 
      from wbb1_1_thread t
      left join wbb1_1_post p ON p.postID = t.firstPostID', $Discussion_Map);
    
    // Comments
    $Comment_Map = array(
      'postID' => 'CommentID',
      'threadID' => 'DiscussionID',
      'userID' => 'InsertUserID',
      'message' => 'Body',
      'editorID' => 'UpdateUserID',
      'deletedByID' => 'DeleteUserID',
    );
    $Ex->ExportTable('Comment', 'select *,
      FROM_UNIXTIME(time) as DateInserted,
      FROM_UNIXTIME(lastEditTime) as DateUpdated,
      FROM_UNIXTIME(deleteTime) as DateDeleted,
      \'BBCode\' as Format,
      from wbb1_1_post', $Comment_Map);
  }

}
?>
