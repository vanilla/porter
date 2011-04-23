<?php
/**
 * WBB3 exporter tool
 *
 * @author Lieuwe Jan Eilander
 *
 * Framework:
 * @copyright Vanilla Forums Inc. 2010
 * @license http://opensource.org/licenses/gpl-2.0.php GNU GPL2
 * @packager VanillaPorter
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
    'wbb1_1_board' => array('boardID'),
    'wbb1_1_post' => array(),
    'wbb1_1_thread' => array(),
    'wcf1_user' => array('userID', 'username', 'email', 'password'),
    'wcf1_group' => array(), 
  );

  /** 
   * Forum-specific export format
   * @param ExportModel $Ex
   */
  protected function ForumExport($Ex)
  {
    // Begin
    $Ex->BeginExport('', 'WBB 3.x');
  
  }

}
