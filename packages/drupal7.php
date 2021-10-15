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
    'Bookmarks' => 0,
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
        // TODO validate password hashing didn't change between drupal 6 and drupal 7.
        $ex->exportTable('User', "
            select
                uid as UserID,
                name as Name,
                pass as Password,
                nullif(concat('drupal_profile/',if(picture = 0, null, picture)), 'drupal_profile/') as Photo,
                concat('md5$$', pass) as Password,
                'Django' as HashMethod,
                mail as Email,
                from_unixtime(created) as DateInserted,
                from_unixtime(login) as DateLastActive
            from :_users
            where uid > 0 and status = 1
        ");

        // Signatures.
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
        ");

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
            from taxonomy_term_data t
            left join taxonomy_term_hierarchy th on th.tid = t.tid
            left join taxonomy_vocabulary tv on tv.vid = t.vid
            where tv.name in ('Forums', 'Discussion boards')
        ");

        // Discussions.
        $discussionMap = array(
            'Body' => array('Column' => 'Body', 'Filter' => array($this, 'convertBase64Attachments')),
        );
        $ex->exportTable('Discussion', "
            select
                n.nid as DiscussionID,
                n.uid as InsertUserID,
                from_unixtime(n.created) as DateInserted,
                if(n.created <> n.changed, from_unixtime(n.changed), null) as DateUpdated,
                if(n.sticky = 1, 2, 0) as Announce,
                f.tid as CategoryID,
                n.title as Name,
                concat(ifnull(r.body_value, b.body_value), ifnull(i.image, '')) as Body,
                'Html' as Format
            from :_node n
            join :_field_data_body b on b.entity_id = n.nid
            left join :_forum f on f.vid = n.vid
            left join :_field_revision_body r on r.revision_id = n.vid
            left join ( select i.nid, concat('\n<img src=\"{$this->path}', replace(uri, 'public://', ''), ' alt=\"', fileName, '\">') as image
                        from :_image i
                        join :_file_managed fm on fm.fid = i.fid
                        where image_size not like '%thumbnail') i on i.nid = n.nid
            where n.status = 1 and n.moderate = 0 and b.deleted = 0 and n.Type not in ('Page', 'webform')
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
                from_unixtime(timestamp) as DateInserted
            from file_managed fm
            join file_usage fu on fu.fid = fm.fid

            union

            select
                f.fid as MediaID,
                f.filemime as Type,
                fu.id as ForeignID,
                if(fu.type = 'node', 'discussion', 'comment') as ForeignTable,
                f.filename as Name,
                concat('drupal_attachments/',substring(f.uri, 10)) as Path,
                f.filesize as Size,
                from_unixtime(timestamp) as DateInserted
            from file_managed_audio f
            join file_usage_audio fu on fu.fid = f.fid
         ");

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
}

// Closing PHP tag required. (make.php)
?>
