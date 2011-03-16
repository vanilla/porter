<?php
/**
 * SMF exporter tool
 *
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */

class Smf2 extends ExportController {

    /** @var array Required tables => columns */
    protected $SourceTables = array(
       'members' => array('id_member', 'member_name', 'passwd', 'email_address', 'timezone_offset', 'posts', 'date_registered','last_login', 'birthdate','avatar',),
       'membergroups' => array('id_group', 'group_name', 'description'),
       'members' => array('id_member', 'id_group'),
       'boards' => array('id_board', 'name', 'description', 'id_parent','board_order'),
       'topics' => array('id_topic', 'id_board', 'id_member_started',  'num_views', 'id_first_msg',
          'num_replies', 'id_last_msg'),
       'messages' => array('id_msg', 'id_topic', 'subject', 'body', 'id_member', 'modified_name','modified_name', 'poster_time', 'modified_time'),
    );

    /**
     * Forum-specific export format.
     * @param ExportModel $Ex
     */
    protected function ForumExport($Ex) {
      // Begin
      $Ex->BeginExport('', 'SMF 2.*', array('HashMethod' => 'smf'));

      // Users
      $User_Map = array(
         'id_member'=>'UserID',
         'member_name'=>'Name',
         'passwd'=>'Password',
         'email_address'=>'Email',
         'timezone_offset'=>'HourOffset',
         'posts'=>array('Column' => 'CountComments', 'Type' => 'int'),
         'birthdate' => 'DateOfBirth'
      );
      $Ex->ExportTable('User', "select m.*,
            FROM_UNIXTIME(nullif(m.date_registered, 0)) as DateFirstVisit,
            FROM_UNIXTIME(nullif(m.date_registered, 0)) as DateInserted,
            FROM_UNIXTIME(nullif(m.last_login,0)) as DateLastActive
         from :_members m", $User_Map);  // ":_" will be replace by database prefix

      // Roles
      $Role_Map = array(
         'id_group'=>'RoleID',
         'group_name'=>'Name',
         'description'=>'Description'
      );
      $Ex->ExportTable('Role', 'select * from :_membergroups', $Role_Map);


      // UserRoles
      $UserRole_Map = array(
         'id_member'=>'UserID',
         'id_group'=>'RoleID'
      );
      $Ex->ExportTable('UserRole', 'select id_member, id_group from :_members where id_group !=0 
       union select m.id_member,g.id_group from :_members m join :_membergroups g on find_in_set(g.id_group,m.additional_groups)', $UserRole_Map);

      // Categories
      $Category_Map = array(
         'id_board'=>'CategoryID',
         'id_parent' =>'ParentCategoryID',
         'name'=>'Name',
         'description'=>'Description',
         'board_order'=>'Sort',
      );
      $Ex->ExportTable('Category', "select name,'' description, cat_order board_order, id_cat id_board, 0 id_parent from :_categories
       union select name, description, board_order, id_board+(select max(id_cat) from :_categories) id_board,
       case id_parent when 0 then id_cat else id_parent+(select max(id_cat) from :_categories) end id_parent
       from :_boards b", $Category_Map);

      // Discussions
      $Discussion_Map = array(
         'id_topic'=>'DiscussionID',
         'id_member_started'=>'InsertUserID',
         'num_views'=>'CountViews',
         'id_first_msg'=>array('Column'=>'FirstCommentID','Type'=>'int')
      );
      $Ex->ExportTable('Discussion', "select t.*,
            t.id_board+(select max(id_cat) from :_categories) as CategoryID,
			'BBCode' as Format,
            t.num_replies as CountComments,
            case t.locked when 1 then 1 else 0 end as Closed,
            case t.is_sticky when 1 then 1 else 0 end as Announce,
            fm.subject as Name,
            fm.body as Body,
            FROM_UNIXTIME(fm.poster_time) as DateInserted,
            FROM_UNIXTIME(lm.poster_time) as DateUpdated,
            FROM_UNIXTIME(lm.poster_time) as DateLastComment
        from :_topics t
        inner join :_messages fm on t.id_first_msg = fm.id_msg
        inner join :_messages lm on t.id_last_msg = lm.id_msg",
        $Discussion_Map);

      // Comments
      $Comment_Map = array(
         'id_msg' => 'CommentID',
         'id_topic' => 'DiscussionID',
         'body' => 'Body',
         'id_member' => 'InsertUserID',
      );

      $Ex->ExportTable('Comment', "select m.*,
			'BBCode' as Format,
			mm.id_member as UpdateUserID,
            FROM_UNIXTIME(m.poster_time) as DateInserted,
            FROM_UNIXTIME(nullif(m.modified_time,0)) as DateUpdated
         from :_messages m left join :_members mm on m.modified_name = mm.member_name
         where m.id_msg not in (select id_first_msg from :_topics)",
         $Comment_Map);

      // End
      $Ex->EndExport();
    }
}
?>