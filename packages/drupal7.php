<?php
/**
 * Vanilla 2 exporter tool for Drupal 7
 *
 * @copyright 2021 Vanilla Forums Inc.
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @package VanillaPorter
 */

$supported['drupal7'] = array('name' => 'Drupal 7', 'prefix' => '');
$supported['drupal7']['CommandLine'] = array(
    'siteID' => array('Vanilla site ID', 'Sx' => '::')
);
$supported['drupal7']['features'] = array(
    'Users' => 1,
    'Passwords' => 1,
    'Categories' => 1,
    'Discussions' => 1,
    'Comments' => 1,
    'Polls' => 0,
    'Roles' => 1,
    'Avatars' => 1,
    'PrivateMessages' => 0,
    'Signatures' => 1,
    'Attachments' => 1,
    'Bookmarks' => 1,
    'Permissions' => 0,
    'Badges' => 0,
    'UserNotes' => 0,
    'Ranks' => 0,
    'Groups' => 0,
    'Tags' => 0,
    'Reactions' => 0,
    'Articles' => 0,
);

class Drupal7 extends ExportController {

    protected $path;
    protected $dbName;
    const PATTERN = "~\"data:image/png;base64,(.*?)\"~";

    /**
     * @param ExportModel $ex
     */
    protected function forumExport($ex) {

        $characterSet = $ex->getCharacterSet('comment');
        if ($characterSet) {
            $ex->characterSet = $characterSet;
        }

        $this->path = 'https://us.v-cdn.net/' . $this->param('siteID', null) . '/uploads/';
        $this->dbName = $this->param('dbname', null);

        if (!is_dir('attachments_' . $this->dbName)) {
            mkdir('attachments_' . $this->dbName);
        }

        // Begin.
        $ex->beginExport('', 'Drupal 7');

        // Users.
        $ex->exportTable('User', "
            select
                u.uid as UserID,
                name as Name,
                pass as Password,
                f.filename as Photo,
                'Django' as HashMethod,
                mail as Email,
                from_unixtime(created) as DateInserted,
                from_unixtime(login) as DateLastActive
            from :_users u
            join :_file_managed f on f.fid = u.picture
            where u.uid > 0 and u.status = 1
        ");

        // Signatures.
        $usermeta_map = array(
            'Value' => array('Column' => 'Value', 'Filter' => array($this, 'universalizeContent'))
        );
        $ex->exportTable('UserMeta', "
            select
                uid as UserID,
                signature as Value,
                'Plugin.Signatures.Sig' as Name
            from :_users u
            where uid > 0 and status = 1 and signature is not null and signature <> ''

            union

            select
                uid as UserID,
                'Html' as Value,
                'Plugins.Signatures.Format' as Name
            from :_users u
            where uid > 0 and status = 1 and signature is not null and signature <> ''

            union

            select
                pv.uid as UserID,
                pv.value as Value,
                pf.name as Name
            from :_profile_value pv
            join :_profile_field pf on pf.fid = pv.fid
            where pv.value <> '' and pv.value <> '0'
        ", $usermeta_map);

        // Roles.
        $ex->exportTable('Role', "
            select
                rid as RoleID,
                name as Name
            from :_role
        ");

        // User Role.
        $ex->exportTable('UserRole', "
            select
                uid as UserID,
                rid as RoleID
            from :_users_roles
         ");

        // Categories.
        $ex->exportTable('Category', "
            select
                t.tid as CategoryID,
                t.name as Name,
                t.description as Description,
                if(th.parent = 0, null, th.parent) as ParentCategoryID
            from :_taxonomy_term_data t
            left join :_taxonomy_term_hierarchy th on th.tid = t.tid
            left join :_taxonomy_vocabulary tv on tv.vid = t.vid
            where tv.name in ('Forums', 'Discussion boards')
        ");

        // Discussions.
        $discussionMap = array(
            'Body' => array('Column' => 'Body', 'Filter' => array($this, 'convertBase64Attachments')),
        );
        $ex->exportTable('Discussion', "
             select
                n.nid as DiscussionID,
                f.tid as CategoryID,
                       n.title as Name,
                concat(ifnull(r.body_value, b.body_value), ifnull(i.image, '')) as Body,
                'Html' as Format,
                n.uid as InsertUserID,
                from_unixtime(n.created) as DateInserted,
                if(n.created <> n.changed, from_unixtime(n.changed), null) as DateUpdated,
                if(n.sticky = 1, 2, 0) as Announce
            from :_node n
            left join :_field_data_body b on b.entity_id = n.nid
            left join :_forum f on f.vid = n.vid
            left join :_field_revision_body r on r.revision_id = n.vid
            left join ( select i.nid, concat('\n<img src=\"{$this->path}', replace(uri, 'public://', ''), '\" alt=\"', fileName, '\">') as image
                        from :_image i
                        join :_file_managed fm on fm.fid = i.fid
                        where image_size not like '%thumbnail') i on i.nid = n.nid
            where n.status = 1 and n.moderate = 0 and n.Type not in ('webform')
        ", $discussionMap);

        // Comments.
        $commentMap = array(
            'Body' => array('Column' => 'Body', 'Filter' => array($this, 'convertBase64Attachments')),
        );
        $ex->exportTable('Comment', "
            select
                c.cid as CommentID,
                c.nid as DiscussionID,
                c.uid as InsertUserID,
                from_unixtime(c.created) as DateInserted,
                if(c.created <> c.changed, from_unixtime(c.changed), null) as DateUpdated,
                concat(
                    -- Title of the commment
                    if(c.subject is not null and c.subject not like 'RE%' and c.subject not like 'Re%' and c.subject <> 'N/A',
                        concat('<b>', c.subject, '</b>\n'), ''),
                    -- Body
                    ifnull(r.comment_body_value, b.comment_body_value)
                ) as Body,
                'Html' as Format
            from :_comment c
            join :_field_data_comment_body b on b.entity_id = c.cid
            left join :_field_revision_comment_body r on r.entity_id = c.cid
            where c.status = 1 and b.deleted = 0
         ", $commentMap);

        //User Discussion
        $userdiscussion_map = array(
            'DiscussionID' => array('Column' => 'DiscussionID', 'Filter' => array($this, 'extractDiscussionID')),
        );
        $ex->exportTable('UserDiscussion',"
            select
                uid as UserID,
                url as DiscussionID,
                1 as BookMarked
            from :_bookmarks
            where url like 'node%'
        ", $userdiscussion_map);

        // User Category
        $usercategory_map = array(
            'CategoryID' => array('Column' => 'CategoryID', 'Filter' => array($this, 'extractCategoryID')),
        );
        $ex->exportTable('UserCategory',"
            select
                uid as UserID,
                url as CategoryID,
                1 as Followed
            from :_bookmarks
            where url like 'forum%'
        ", $usercategory_map);

        // Media.
        $ex->exportTable('Media', "
             select
                fm.fid as MediaID,
                fm.filemime as Type,
                fu.id as ForeignID,
                if(fu.type = 'node', 'discussion', 'comment') as ForeignTable,
                fm.filename as Name,
                concat('drupal_attachments/',substring(fm.uri, 10)) as Path,
                fm.filesize as Size,
                fm.uid as InsertUserID,
                from_unixtime(timestamp) as DateInserted
            from :_file_managed fm
            join :_file_usage fu on fu.fid = fm.fid
            where fu.type = 'node'

            union

            select
                f.fid as MediaID,
                f.filemime as Type,
                fu.id as ForeignID,
                if(fu.type = 'node', 'discussion', 'comment') as ForeignTable,
                f.filename as Name,
                concat('drupal_attachments/',substring(f.uri, 10)) as Path,
                f.filesize as Size,
                f.uid as InsertUserID,
                from_unixtime(timestamp) as DateInserted
            from :_file_managed_audio f
            join :_file_usage_audio fu on fu.fid = f.fid
            where fu.type = 'node'
         ");

        $ex->exportTable('Conversation', "
        select
            i.thread_id as ConversationID,
            m.subject as Subject,
            from_unixtime(m.timestamp) as DateInserted,
            m.author as InsertUserID
        from
            (select
                thread_id,
                min(mid) as mid
            from :_pm_index
            where deleted = 0
            group by thread_id) i
        join :_pm_message m on m.mid = i.mid
        ");

        $ex->exportTable('ConversationMessage', "
            select
                m.mid as MessageID,
                i.thread_id as ConversationID,
                m.body as Body,
                'Html' as Format,
                m.author as InsertUserID,
                from_unixtime(m.timestamp) as DateInserted
            from :_pm_message m
            join (select distinct mid, thread_id from :_pm_index) i on i.mid = m.mid
        ");

        $ex->exportTable('UserConversation', "
            select
               recipient as UserID,
               thread_id as ConversationID
            from :_pm_index
            group by recipient, thread_id
        ");

        $this->nestedQuotesMessages();
        $ex->endExport();
    }

    public function convertBase64Attachments($value, $field, $row){

        $this->imageCount = 1;
        $postId = $row['CommentID'] ?? $row['DiscussionID'];

        $value = preg_replace_callback(
            self::PATTERN,
            function ($matches) use($postId) {

                $file = base64_decode($matches[1]);
                if ($file !== false) {
                    $filename = "{$postId}_{$this->imageCount}.png";
                    $this->imageCount++;
                    file_put_contents('attachments_' . $this->dbName . '/' . $filename, $file);
                    return "\"$this->path/$filename\"";
                }

            },
            $value);

        return $value;
    }

    public function array_key_first(array $a) {
        return array_keys($a)[0];
    }

    public function universalizeContent($value, $field, $row) {

        if(preg_match('~a:\d~', $value)){
            $value = preg_replace_callback(
                '!s:(\d+):"(.*?)";!',
                function($m) {
                    return 's:'.strlen($m[2]).':"'.$m[2].'";';
                },
                $value);

            $unserializedValue = unserialize($value);
            if (unserialize($value) !== false && count($unserializedValue) > 0) {
                return $this->array_key_first($unserializedValue);
            }
        }

        return $value;
    }

    public function extractDiscussionID($value, $field, $row) {
        preg_match('~node/(\d+)~', $value, $matches);
        if(isset($matches[1])) {
            return $matches[1];
        }
        return $value;
    }

    public function extractCategoryID($value, $field, $row) {
        preg_match('~forum/(\d+)~', $value, $matches);
        if(isset($matches[1])) {
            return $matches[1];
        }
        return $value;
    }

    public function nestedQuotesMessages() {
        echo "// Run nested.sql to quote the nested comments. ***" . PHP_EOL;
        file_put_contents("nested.sql","CREATE TABLE GDN_Comment_copy LIKE GDN_Comment;
          INSERT INTO GDN_Comment_copy SELECT * FROM GDN_Comment;
          update GDN_Comment c,
                (select c.CommentID, concat('<blockquote class=\"Quote\" rel=\"',ifnull(u.Name, 'unknown'), '\"><p>', p.Body, '</p></blockquote>\n') as body
                from GDN_Comment_copy c
                join (select cid, pid from `{$this->param('dbname')}`.comment where pid > 0) lc on lc.cid = c.CommentID
		        join GDN_Comment_copy p on p.CommentID = lc.pid
		        left join GDN_User u on u.UserID = p.InsertUserID) p
		set c.Body = concat(p.Body, c.Body)
		where c.CommentID = p.CommentID;
		DROP TABLE GDN_Comment_copy;
		");
    }
}

// Closing PHP tag required. (make.php)
?>
