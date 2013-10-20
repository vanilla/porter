<?php

$Supported['Xoops_cbb'] = array('name'=>'XOOPS (CBB) 2.*', 'prefix' => 'xoops_');

class Xoops_cbb extends ExportController {

   /** @var array Required tables => columns */
   protected $SourceTables = array(
      'users' => array(
          'uid',
          'uname',
          'pass',
          'email',
          'timezone_offset',
          'posts',
          'user_regdate',
          'last_login'
          ),
      'groups' => array('groupid', 'name', 'description'),
      'groups_users_link' => array('uid', 'groupid'),
      'bb_forums' => array('forum_id', 'forum_name', 'forum_desc', 'forum_order'),
      'bb_topics' => array('topic_id', 'forum_id', 'topic_poster',  'topic_title', 'topic_views', 'topic_last_post_id', 'topic_replies', 'topic_status', 'topic_time'),
      'bb_posts' => array('post_id', 'topic_id', 'uid', 'post_time'),
      'bb_posts_text' => array('post_id', 'post_text'),
      'priv_msgs' => array('msg_id', 'subject', 'from_userid', 'to_userid', 'msg_time', 'msg_text'),
   );

   /**
    * Forum-specific export format.
    * @param ExportModel $Ex
    */
   protected function ForumExport($Ex) {
      // Begin
      $Ex->BeginExport('', 'XOOPS CBB', array('HashMethod' => 'md5'));
      // Users
      $User_Map = array(
         'uid'=>'UserID',
         'uname'=>'Name',
         'pass'=>'Password',
         'email'=>'Email',
         'timezone_offset'=>'HourOffset',
         'posts'=>array('Column' => 'CountComments', 'Type' => 'int')
      );
      $Ex->ExportTable('User', "select *,
            FROM_UNIXTIME(nullif(user_regdate, 0)) as DateFirstVisit,
            FROM_UNIXTIME(nullif(last_login, 0)) as DateLastActive,
            FROM_UNIXTIME(nullif(user_regdate, 0)) as DateInserted
         from :_users", $User_Map);  // ":_" will be replace by database prefix


      // Roles
      $Role_Map = array(
         'groupid'=>'RoleID',
         'name'=>'Name',
         'description'=>'Description'
      );
      // Groups
      $Ex->ExportTable('Role', 'select * from :_groups', $Role_Map);


      // UserRoles
      $UserRole_Map = array(
         'uid'=>'UserID',
         'groupid'=>'RoleID'
      );
      // Memberships
      $Ex->ExportTable('UserRole', 'select uid, groupid from :_groups_users_link', $UserRole_Map);

      // Categories
      $Category_Map = array(
         'forum_id'=>'CategoryID',
         'forum_name'=>'Name',
         'parent_forum' => 'ParentCategoryID',
         'forum_desc'=>'Description',
      );
      $Ex->ExportTable('Category',
"select
  f.forum_id,
  f.forum_name,
  f.parent_forum,
  f.forum_desc
from :_bb_forums f", $Category_Map);

      // Discussions
      $Discussion_Map = array(
         'topic_id'=>'DiscussionID',
         'forum_id'=>'CategoryID',
         'topic_poster'=>'InsertUserID',
         'topic_title'=>'Name',
         'Format'=>'Format',
         'topic_views'=>'CountViews'
      );
      $Ex->ExportTable('Discussion', "select t.*,
        'BBCode' as Format,
         case t.topic_status when 1 then 1 else 0 end as Closed,
         FROM_UNIXTIME(t.topic_time) as DateInserted
        from :_bb_topics t",
        $Discussion_Map);

      // Comments
      $Comment_Map = array(
         'post_id' => 'CommentID',
         'topic_id' => 'DiscussionID',
         'post_text' => array('Column'=>'Body'),
         'Format' => 'Format',
         'uid' => 'InsertUserID'
      );
      $Ex->ExportTable('Comment', "select p.*, pt.post_text,
        'BBCode' as Format,
         FROM_UNIXTIME(p.post_time) as DateInserted,
         FROM_UNIXTIME(p.post_time) as DateUpdated
         from :_bb_posts p inner join :_bb_posts_text pt on p.post_id = pt.post_id",
         $Comment_Map);

      // Conversations tables.
      $Ex->Query("drop table if exists z_pmto;");

      $Ex->Query("create table z_pmto (
id int unsigned,
userid int unsigned,
primary key(id, userid));");

      $Ex->Query("insert ignore z_pmto (id, userid)
select msg_id, from_userid
from :_priv_msgs;");

      $Ex->Query("insert ignore z_pmto (id, userid)
select msg_id, to_userid
from :_priv_msgs;");

      $Ex->Query("drop table if exists z_pmto2;");

      $Ex->Query("create table z_pmto2 (
  id int unsigned,
  userids varchar(250),
  primary key (id)
);");

      $Ex->Query("insert ignore z_pmto2 (id, userids)
select
  id,
  group_concat(userid order by userid)
from z_pmto
group by id;");

      $Ex->Query("drop table if exists z_pm;");

      $Ex->Query("create table z_pm (
  id int unsigned,
  subject varchar(255),
  subject2 varchar(255),
  userids varchar(250),
  groupid int unsigned
);");

      $Ex->Query("insert z_pm (
  id,
  subject,
  subject2,
  userids
)
select
  pm.msg_id,
  pm.subject,
  case when pm.subject like 'Re: %' then trim(substring(pm.subject, 4)) else pm.subject end as subject2,
  t.userids
from :_priv_msgs pm
join z_pmto2 t
  on t.id = pm.msg_id;");

      $Ex->Query("create index z_idx_pm on z_pm (id);");

      $Ex->Query("drop table if exists z_pmgroup;");

      $Ex->Query("create table z_pmgroup (
  groupid int unsigned,
  subject varchar(255),
  userids varchar(250)
);");

      $Ex->Query("insert z_pmgroup (
  groupid,
  subject,
  userids
)
select
  min(pm.id),
  pm.subject2,
  pm.userids
from z_pm pm
group by pm.subject2, pm.userids;");

      $Ex->Query("create index z_idx_pmgroup on z_pmgroup (subject, userids);");
      $Ex->Query("create index z_idx_pmgroup2 on z_pmgroup (groupid);");

      $Ex->Query("update z_pm pm
join z_pmgroup g
  on pm.subject2 = g.subject and pm.userids = g.userids
set pm.groupid = g.groupid;");

      // Conversations.
      $Conversation_Map = array(
         'msg_id' => 'ConversationID',
         'from_userid' => 'InsertUserID',
         'RealSubject' => array('Column' => 'Subject', 'Type' => 'varchar(250)')
      );

      $Ex->ExportTable('Conversation', "select
  g.subject as RealSubject,
  pm.*,
  from_unixtime(pm.msg_time) as DateInserted
from :_priv_msgs pm
join z_pmgroup g
  on g.groupid = pm.msg_id", $Conversation_Map);

      // Coversation Messages.
      $ConversationMessage_Map = array(
          'msg_id' => 'MessageID',
          'groupid' => 'ConversationID',
          'msg_text' => array('Column' => 'Body'),
          'from_userid' => 'InsertUserID'
      );
      $Ex->ExportTable('ConversationMessage',
      "select
         pm.*,
         pm2.groupid,
         'BBCode' as Format,
         FROM_UNIXTIME(pm.msg_time) as DateInserted
       from :_priv_msgs pm
       join z_pm pm2
         on pm.msg_id = pm2.id", $ConversationMessage_Map);

      // User Conversation.
      $UserConversation_Map = array(
         'userid' => 'UserID',
         'groupid' => 'ConversationID'
      );
      $Ex->ExportTable('UserConversation',
      "select
         g.groupid,
         t.userid
       from z_pmto t
       join z_pmgroup g
         on g.groupid = t.id;", $UserConversation_Map);

      $Ex->Query('drop table if exists z_pmto');
      $Ex->Query('drop table if exists z_pmto2;');
      $Ex->Query('drop table if exists z_pm;');
      $Ex->Query('drop table if exists z_pmgroup;');

      // End
      $Ex->EndExport();
   }

}