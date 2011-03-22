<?php
/**
 * phpBB exporter tool
 *
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */

class Phpbb3 extends ExportController {

   /** @var array Required tables => columns */
   protected $SourceTables = array(
      'attachments' => array('attach_id', 'post_msg_id', 'topic_id', 'poster_id', 'physical_filename', 'real_filename', 'mimetype', 'filesize', 'filetime'),
      'users' => array('user_id', 'username', 'user_password', 'user_email', 'user_timezone', 'user_posts', 'user_regdate', 
         'user_lastvisit', 'user_regdate'),
      'groups' => array('group_id', 'group_name', 'group_desc'),
      'user_group' => array('user_id', 'group_id'),
      'forums' => array('forum_id', 'forum_name', 'forum_desc', 'left_id', 'parent_id'),
      'topics' => array('topic_id', 'forum_id', 'topic_poster',  'topic_title', 'topic_views', 'topic_first_post_id', 
         'topic_replies', 'topic_status', 'topic_type', 'topic_time', 'topic_last_post_time', 'topic_last_post_time'),
      'posts' => array('post_id', 'topic_id', 'post_text', 'poster_id', 'post_edit_user', 'post_time', 'post_edit_time'),
      'bookmarks' => array('user_id', 'topic_id')
   );

   /**
    * Forum-specific export format.
    * @param ExportModel $Ex
    */
   protected function ForumExport($Ex) {
      // Begin
      $Ex->BeginExport('', 'phpBB 3.*', array('HashMethod' => 'phpBB'));

      // Users
      $User_Map = array(
         'user_id'=>'UserID',
         'username'=>'Name',
         'user_password'=>'Password',
         'user_email'=>'Email',
         'user_timezone'=>'HourOffset',
         'user_posts'=>array('Column' => 'CountComments', 'Type' => 'int')
      );
      $Ex->ExportTable('User', "select *,
            FROM_UNIXTIME(nullif(user_regdate, 0)) as DateFirstVisit,
            FROM_UNIXTIME(nullif(user_lastvisit, 0)) as DateLastActive,
            FROM_UNIXTIME(nullif(user_regdate, 0)) as DateInserted
         from :_users", $User_Map);  // ":_" will be replace by database prefix


      // Roles
      $Role_Map = array(
         'group_id'=>'RoleID',
         'group_name'=>'Name',
         'group_desc'=>'Description'
      );
      $Ex->ExportTable('Role', 'select * from :_groups', $Role_Map);


      // UserRoles
      $UserRole_Map = array(
         'user_id'=>'UserID',
         'group_id'=>'RoleID'
      );
      $Ex->ExportTable('UserRole', 'select user_id, group_id from :_users
         union
         select user_id, group_id from :_user_group', $UserRole_Map);

      // Categories
      $Category_Map = array(
         'forum_id'=>'CategoryID',
         'forum_name'=>'Name',
         'forum_desc'=>'Description',
         'left_id'=>'Sort'
      );
      $Ex->ExportTable('Category', "select *,
         nullif(parent_id,0) as ParentCategoryID
         from :_forums", $Category_Map);


      // Discussions
      $Discussion_Map = array(
         'topic_id'=>'DiscussionID',
         'forum_id'=>'CategoryID',
         'topic_poster'=>'InsertUserID',
         'topic_title'=>'Name',
			'Format'=>'Format',
         'topic_views'=>'CountViews',
         'topic_first_post_id'=>array('Column'=>'FirstCommentID','Type'=>'int')
      );
      $Ex->ExportTable('Discussion', "select t.*,
				'BBCode' as Format,
            topic_replies+1 as CountComments,
            case t.topic_status when 1 then 1 else 0 end as Closed,
            case t.topic_type when 1 then 1 else 0 end as Announce,
            FROM_UNIXTIME(t.topic_time) as DateInserted,
            FROM_UNIXTIME(t.topic_last_post_time) as DateUpdated,
            FROM_UNIXTIME(t.topic_last_post_time) as DateLastComment
         from :_topics t", $Discussion_Map);

      // Comments
      $Comment_Map = array(
         'post_id' => 'CommentID',
         'topic_id' => 'DiscussionID',
         'post_text' => array('Column'=>'Body','Filter'=>array($this, 'RemoveBBCodeUIDs')),
			'Format' => 'Format',
         'poster_id' => 'InsertUserID',
         'post_edit_user' => 'UpdateUserID'
      );
      $Ex->ExportTable('Comment', "select p.*,
				'BBCode' as Format,
            FROM_UNIXTIME(p.post_time) as DateInserted,
            FROM_UNIXTIME(nullif(p.post_edit_time,0)) as DateUpdated
         from :_posts p", $Comment_Map);

      // UserDiscussion
		$UserDiscussion_Map = array(
			'user_id' =>  'UserID',
         'topic_id' => 'DiscussionID');
      $Ex->ExportTable('UserDiscussion', "select b.*,
         1 as Bookmarked
         from :_bookmarks b", $UserDiscussion_Map);

      // Media
      if ($Ex->Exists('attachments')) {
         $Media_Map = array(
            'attach_id' => 'MediaID',
            'real_filename' => 'Name',
            'mimetype' => 'Type',
            'filesize' => 'Size',
            'physical_filename' => array('Column' => 'Path', 'Filter' => array($this, 'BuildMediaPath')),
            'poster_id' => 'InsertUserID'
         );
         $Ex->ExportTable('Media',
            "select a.*, 
               p.post_id as post_id,
               'local' as StorageMethod, 
               IF(p.post_id != p.topic_id, 'comment', 'discussion') as ForeignTable,
               IF(p.post_id != p.topic_id, post_id, a.topic_id) as ForeignID,
               FROM_UNIXTIME(a.filetime) as DateInserted
            from :_attachments a
               left join :_posts p ON a.post_msg_id = p.post_id", $Media_Map);
      }

      // End
      $Ex->EndExport();
   }

   public function ConvertText($Value, $Field, $Row) {
      //RemoveBBCodeUIDs
      $UID = $Row['bbcode_uid'];
      $Value = str_replace(':'.$UID, '', $Value);
      if(preg_match_all('#<!-- s(.{2,3}) --><img src="{SMILIES_PATH}/.*\.gif" alt=".+" title="[a-zA-Z]+" /><!-- s.{2,3} -->#', $Value, $Matches)) {
            // Replace emoction followed by space (Emotify does not work without space)
            $Value = preg_replace('#<!-- s(.{2,3}) --><img src="{SMILIES_PATH}/.*\.gif" alt=".+" title="[a-zA-Z]+" /><!-- s.{2,3} -->#', '$1 ', $Value);
      }
      return $Value;
   }

   function BuildMediaPath($Value, $Field, $Row) {

             return '/'.$Row['physical_filename'];
   }
}
?>