<?php

ini_set('default_socket_timeout', -1);  //php configuration settings do not timeout

require_once dirname(__FILE__) . DIRECTORY_SEPARATOR . 'config.php';

class MyngleQueue
{

    private static $strServer   = "";
    private static $strUser     = "";
    private static $strPwd      = "";
    private static $strDatabase = "";
    private static $mysqli      = null;

    static public function printDebug($mess, $trace = false)
    {
        echo date("c") . " " . trim($mess) . "\n";
        if ($trace)
        {
            debug_print_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        }
    }

    static public function setMysqlConfig($strServer, $strUser, $strPwd)
    {
        self::$strServer = $strServer;
        self::$strUser   = $strUser;
        self::$strPwd    = $strPwd;
    }

    static public function setDatabase($strDatabase)
    {
        if (self::$strDatabase != $strDatabase && !empty(self::$mysqli))
        {

            try
            {
                if (self::$mysqli->ping() && self::$mysqli->select_db($strDatabase))
                {
                    self::printDebug("Change database successfully");
                    return;
                }
                else
                {
                    self::printDebug("Change database while no connection alive, close then reconect");
                    @self::$mysqli->close();
                }
            }
            catch (Exception $exc)
            {
                self::printDebug($exc->getMessage());
                self::printDebug($exc->getTraceAsString());
            }

            self::$mysqli = null;
        }

        self::$strDatabase = $strDatabase;
    }

    // <editor-fold defaultstate="collapsed" desc="MySQL base">

    static private function mysqlInit()
    {
        //Check is current connection alive
        try
        {
            if (!empty(self::$mysqli) && self::$mysqli->ping())
            {
                self::printDebug("Reuse the current connection.");
                return;
            }
        }
        catch (Exception $exc)
        {
            self::printDebug($exc->getMessage());
            self::printDebug($exc->getTraceAsString());
        }

        self::$mysqli = new mysqli(self::$strServer, self::$strUser, self::$strPwd, self::$strDatabase);

        if (self::$mysqli->connect_errno)
        {
            self::printDebug("Failed to connect to MySQL: " . self::$mysqli->connect_error . "", true);
            exit(); //exit then the service will be restarted
        }
        // Turn autocommit off
        self::$mysqli->autocommit(FALSE);
    }

    static private function mysqlUpdate($sql)
    {
        if (!self::$mysqli->query($sql))
        {
            self::printDebug("MySQL error: " . self::$mysqli->error . "", true);
            exit;
        }
    }

    static private function mysqlQuery($sql)
    {
        $result = self::$mysqli->query($sql);
        if (empty($result))
        {
            self::printDebug("MySQL error: " . self::$mysqli->error . "", true);
            return FALSE;
        }
        $row = $result->fetch_array(MYSQLI_ASSOC);
        $result->free_result();

        return $row;
    }

    static private function mysqlCommit()
    {
        // Commit transaction
        if (!self::$mysqli->commit())
        {
            self::printDebug("Commit transaction failed");
            return false;
        }

        //self::$mysqli->close();//Not close, keep it open for next command

        return true;
    }

    // </editor-fold>

    static public function createRedis()
    {
        $redis = new Redis();
        $redis->connect("127.0.0.1", 6379);

        return $redis;
    }

    // <editor-fold defaultstate="collapsed" desc="Process for classes table">

    static private function classesAfterInsert($redis, $data)
    {
        self::printDebug("Update class id on locker");
        self::mysqlUpdate("CALL `create_classes_teachers_locker`({$data->NEW->id}, {$data->NEW->time}, {$data->NEW->duration}, {$data->NEW->teacher_id}, {$data->NEW->status}, 3, 0, 0, 0, 0)");

        self::printDebug("Creating audit");
        self::mysqlUpdate("INSERT INTO classaudit(classid, teacher_id, TIME, created, modified, createdaudit, opened, STATUS, new_time, new_lesson, policy_id, ext_status, duration, lesson_type, username, actiontype, is_teacher_fault)
                    VALUES('{$data->NEW->id}', '{$data->NEW->teacher_id}', '{$data->NEW->time}', '{$data->NEW->created}', '{$data->NEW->modified}', UNIX_TIMESTAMP(), '{$data->NEW->opened}', '{$data->NEW->status}', '{$data->NEW->new_time}', '{$data->NEW->new_lesson}',
                    '{$data->NEW->policy_id}', '{$data->NEW->ext_status}', '{$data->NEW->duration}', '{$data->NEW->lesson_type}', '{$data->USER}', 'Insert', '{$data->NEW->is_teacher_fault}')");

        $AddedScore      = 0;
        $NumberOfClasses = 0;
        if ($data->NEW->status >= 80 && $data->NEW->status < 90)
        {
            $AddedScore      = 1;
            $NumberOfClasses = 1;
        }
        else if ($data->NEW->status >= 90 && !empty($data->NEW->is_teacher_fault))
        {
            $AddedScore = -1;
        }
        if (($AddedScore <> 0) OR ($NumberOfClasses <> 0))
        {
            self::printDebug("Updating number of classes on users statistics $NumberOfClasses");
            $sql = "
                INSERT INTO users_statistics(user_id, score, numberofclasses)
                    VALUES({$data->NEW->teacher_id}, $AddedScore, $NumberOfClasses)
                ON DUPLICATE KEY UPDATE
                    numberofclasses = numberofclasses + $NumberOfClasses,
                    score = score + $AddedScore
                ";
            self::mysqlUpdate($sql);
        }

        self::printDebug("Calling remove_from_tac_availability");
        self::mysqlUpdate("call remove_from_tac_availability({$data->NEW->id}, {$data->NEW->time}, {$data->NEW->duration}, {$data->NEW->lesson_type}, {$data->NEW->teacher_id}, {$data->NEW->status})");

        return true;
    }

    static private function classesAfterDelete($redis, $data)
    {
        self::printDebug("Creating audit");
        $sql = "INSERT INTO classaudit(	classid, teacher_id, TIME, created, modified, createdaudit, opened, STATUS, new_time, new_lesson,
          policy_id, ext_status, duration, lesson_type, username, actiontype, is_teacher_fault)
          VALUES('{$data->OLD->id}', '{$data->OLD->teacher_id}', '{$data->OLD->time}', '{$data->OLD->created}', '{$data->OLD->modified}', UNIX_TIMESTAMP(), '{$data->OLD->opened}', '{$data->OLD->status}', '{$data->OLD->new_time}', '{$data->OLD->new_lesson}', '{$data->OLD->policy_id}', '{$data->OLD->ext_status}', '{$data->OLD->duration}', '{$data->OLD->lesson_type}', '{$data->USER}', 'Delete', '{$data->OLD->is_teacher_fault}')";
        self::mysqlUpdate($sql);

        $AddedScore      = 0;
        $NumberOfClasses = 0;
        if (($data->OLD->status >= 80 && $data->OLD->status < 90))
        {
            $AddedScore      = -1;
            $NumberOfClasses = -1;
        }
        else if ($data->OLD->status >= 90 && !empty($data->OLD->is_teacher_fault))
        {
            $AddedScore = 1;
        }
        if ($AddedScore != 0 || $NumberOfClasses != 0)
        {
            self::printDebug("Updating number of classes on users statistics $NumberOfClasses");

            $sql = "
            UPDATE 	users_statistics
            SET 	score = score + $AddedScore,
                    numberofclasses = numberofclasses + $NumberOfClasses
            WHERE 	user_id = {$data->OLD->teacher_id}
            ";

            self::mysqlUpdate($sql);
        }

        return true;
    }

    static private function classesAfterUpdate($redis, $data)
    {
        self::printDebug("Creating audit");
        $sql = "INSERT INTO classaudit(	classid, teacher_id, TIME, created, modified, createdaudit, opened, STATUS, new_time, new_lesson,
          policy_id, ext_status, duration, lesson_type, username, actiontype, is_teacher_fault)
          VALUES('{$data->OLD->id}', '{$data->OLD->teacher_id}', '{$data->OLD->time}', '{$data->OLD->created}', '{$data->OLD->modified}', UNIX_TIMESTAMP(), '{$data->OLD->opened}', '{$data->OLD->status}', '{$data->OLD->new_time}', '{$data->OLD->new_lesson}', '{$data->OLD->policy_id}', '{$data->OLD->ext_status}', '{$data->OLD->duration}', '{$data->OLD->lesson_type}', '{$data->USER}', 'Update', '{$data->OLD->is_teacher_fault}')";
        self::mysqlUpdate($sql);

        $AddedScore      = 0;
        $NumberOfClasses = 0;

        IF (($data->NEW->status >= 80 AND $data->NEW->status < 90) OR ($data->OLD->status >= 80 AND $data->OLD->status < 90))
        {
            IF (($data->NEW->status >= 80 AND $data->NEW->status < 90) AND!($data->OLD->status >= 80 AND $data->OLD->status < 90))
            {
                $AddedScore      = $AddedScore + 1;
                $NumberOfClasses = $NumberOfClasses + 1;
            }
            IF (!($data->NEW->status >= 80 AND $data->NEW->status < 90) AND ($data->OLD->status >= 80 AND $data->OLD->status < 90))
            {
                $AddedScore      = $AddedScore - 1;
                $NumberOfClasses = $NumberOfClasses - 1;
            }
        }
        $AddedScore = $AddedScore + $data->OLD->is_teacher_fault - $data->NEW->is_teacher_fault + $data->OLD->teacher_late - $data->NEW->teacher_late;

        IF (($NumberOfClasses <> 0))
        {
            self::printDebug("Updating number of classes on users statistics $NumberOfClasses");
            $sql = "
                INSERT INTO users_statistics(user_id,  numberofclasses)
                    VALUES({$data->NEW->teacher_id}, $NumberOfClasses)
                ON DUPLICATE KEY UPDATE
                    numberofclasses = numberofclasses + $NumberOfClasses
                ";
            self::mysqlUpdate($sql);
        }

        IF (
                $data->NEW->time != $data->OLD->time ||
                $data->NEW->status != $data->OLD->status ||
                $data->NEW->duration != $data->OLD->duration ||
                $data->NEW->teacher_id != $data->OLD->teacher_id ||
                $data->NEW->lesson_type != $data->OLD->lesson_type
        )
        {

            IF ($data->NEW->status < 80)
            {
                self::printDebug("Calling remove_from_tac_availability");
                $sql = "call remove_from_tac_availability({$data->OLD->id}, {$data->NEW->time}, {$data->NEW->duration}, {$data->NEW->lesson_type}, {$data->NEW->teacher_id}, {$data->NEW->status})";
            }
            ELSE
            {
                self::printDebug("Calling add_to_tac_availability");
                $sql = "call add_to_tac_availability({$data->OLD->id}, {$data->NEW->time}, {$data->NEW->duration}, {$data->NEW->lesson_type}, {$data->NEW->teacher_id}, {$data->NEW->status})";
            }
            self::mysqlUpdate($sql);
        }


        return true;
    }

    // </editor-fold>
    // <editor-fold defaultstate="collapsed" desc="Process for classes table">

    static private function classesUsersAfterInsert($redis, $data)
    {

        self::printDebug("Create audit");
        $sql = "INSERT INTO classes_users_audit(
              class_id, student_id, joined, confirmed, STATUS, ext_status, price, promotion_price, TYPE,
              updated_monthpayment, pay_time, class_user_id, username, actiontype, modified, created, createdaudit, is_student_fault)
                VALUES ('{$data->NEW->class_id}', '{$data->NEW->student_id}', '{$data->NEW->joined}', '{$data->NEW->confirmed}', '{$data->NEW->status}', '{$data->NEW->ext_status}', '{$data->NEW->price}', '{$data->NEW->promotion_price}', '{$data->NEW->type}',
                        '{$data->NEW->updated_monthpayment}', '{$data->NEW->pay_time}', '{$data->NEW->class_user_id}', '{$data->USER}', 'Insert', '{$data->NEW->modified}', '{$data->NEW->created}', UNIX_TIMESTAMP(), '{$data->NEW->is_student_fault}')";
        self::mysqlUpdate($sql);
        //self::printDebug("$sql");

        $AddedScore      = 0;
        $NumberOfClasses = 0;
        if ($data->NEW->status >= 80 && $data->NEW->status < 90)
        {
            $AddedScore      = 1;
            $NumberOfClasses = 1;
        }
        else if ($data->NEW->status >= 90 && !empty($data->NEW->is_student_fault))
        {
            $AddedScore = -1;
        }
        if (($AddedScore <> 0 OR $NumberOfClasses <> 0))
        {
            self::printDebug("Updating number of classes on users statistics $NumberOfClasses");
            $sql = "
                INSERT INTO users_statistics(user_id, score, numberofclasses)
                    VALUES({$data->NEW->student_id}, $AddedScore, $NumberOfClasses)
                ON DUPLICATE KEY UPDATE
                    numberofclasses = numberofclasses + $NumberOfClasses,
                    score = score + $AddedScore
                ";
            self::mysqlUpdate($sql);
        }
        else
        {
            self::printDebug("Don't need to update user statistics");
        }

        IF ($data->NEW->status >= 80 AND $data->NEW->status < 90)
        {
            $sql = "SELECT 	c.group_id
                    FROM 	courses c INNER JOIN lessons l ON c.id = l.course_id
                          INNER JOIN classes_lessons cl ON l.id = cl.lesson_id
                    WHERE 	cl.class_id = {$data->NEW->class_id} LIMIT 1";

            $course_id = self::mysqlQuery($sql);

            if (!empty($course_id))
            {
                self::printDebug("Create courses_students");
                $sql = "INSERT IGNORE INTO courses_students(course_id, student_id)
                        VALUES('{$course_id['group_id']}', {$data->NEW->student_id})";
                self::mysqlUpdate($sql);
            }
        }

        self::printDebug("Calling recheck_tac_availability_for_group_class");
        self::mysqlUpdate("call recheck_tac_availability_for_group_class({$data->NEW->class_id})");

        return true;
    }

    static private function classesUsersAfterUpdate($redis, $data)
    {

        self::printDebug("Create audit");
        $sql = "INSERT INTO classes_users_audit(
              class_id, student_id, joined, confirmed, STATUS, ext_status, price, promotion_price, TYPE,
              updated_monthpayment, pay_time, class_user_id, username, actiontype, modified, created, createdaudit, is_student_fault)
                VALUES ('{$data->NEW->class_id}', '{$data->NEW->student_id}', '{$data->NEW->joined}', '{$data->NEW->confirmed}', '{$data->NEW->status}', '{$data->NEW->ext_status}', '{$data->NEW->price}', '{$data->NEW->promotion_price}', '{$data->NEW->type}',
                        '{$data->NEW->updated_monthpayment}', '{$data->NEW->pay_time}', '{$data->NEW->class_user_id}', '{$data->USER}', 'Update', '{$data->NEW->modified}', '{$data->NEW->created}', UNIX_TIMESTAMP(), '{$data->NEW->is_student_fault}')";
        self::mysqlUpdate($sql);
        //self::printDebug("$sql");

        $AddedScore           = 0;
        $NumberOfClasses      = 0;
        $AddedScoreForTeacher = 0;
        if (($data->NEW->status >= 80 && $data->NEW->status < 90) || ($data->OLD->status >= 80 && $data->OLD->status < 90))
        {
            IF (($data->NEW->status >= 80 && $data->NEW->status < 90) && !($data->OLD->status >= 80 && $data->OLD->status < 90))
            {
                $AddedScore      = $AddedScore + 1;
                $NumberOfClasses = $NumberOfClasses + 1;
            }
            if (!($data->NEW->status >= 80 AND $data->NEW->status < 90) AND ($data->OLD->status >= 80 AND $data->OLD->status < 90))
            {
                $AddedScore      = $AddedScore - 1;
                $NumberOfClasses = $NumberOfClasses - 1;
            }
        }
        $AddedScore = $AddedScore + $data->OLD->is_student_fault - $data->NEW->is_student_fault;

        if (( $NumberOfClasses <> 0))
        {
            self::printDebug("Updating number of classes on users statistics $NumberOfClasses");
            $sql = "
                INSERT INTO users_statistics(user_id, numberofclasses)
                    VALUES({$data->NEW->student_id}, $NumberOfClasses)
                ON DUPLICATE KEY UPDATE
                    numberofclasses = numberofclasses + $NumberOfClasses
                ";
            self::mysqlUpdate($sql);
        }


        if (($data->NEW->status = 98 OR $data->OLD->status <> 98))
        {
            $AddedScoreForTeacher = 1;
        }
        if (($data->NEW->status <> 98 OR $data->OLD->status = 98))
        {
            $AddedScoreForTeacher = -1;
        }
        $sql = "SELECT 	c.group_id
                FROM 	courses c INNER JOIN lessons l ON c.id = l.course_id
                      INNER JOIN classes_lessons cl ON l.id = cl.lesson_id
                WHERE 	cl.class_id = {$data->NEW->class_id} LIMIT 1";

        $course_id = self::mysqlQuery($sql);

        if (!empty($course_id))
        {

            IF ($data->NEW->status >= 80 && $data->NEW->status < 90 && !($data->OLD->status >= 80 && $data->OLD->status < 90))
            {
                self::printDebug("Create course_students");
                $sql = "INSERT IGNORE INTO courses_students(course_id, student_id)
                        VALUES('{$course_id['group_id']}', {$data->NEW->student_id})";
                self::mysqlUpdate($sql);
            }
            else if (($data->OLD->status >= 80 && $data->OLD->status < 90) && !($data->NEW->status >= 80 && $data->NEW->status < 90))
            {
                $sql   = "
                          SELECT 	count(*) c
                          FROM 	`courses` AS `c` INNER JOIN `lessons` AS `l` ON l.course_id = c.id
                                INNER JOIN `classes_lessons` AS `cl` ON cl.lesson_id = l.id
                                INNER JOIN `classes_users` AS `cu` ON cu.class_id = cl.class_id
                          WHERE 	c.group_id = {$course_id['group_id']} AND cu.student_id = {$data->OLD->student_id} AND cu.status >= 80  AND cu.status < 90
                        ";
                $check = self::mysqlQuery($sql);

                if (empty($check) || empty($check['c']))
                {
                    self::printDebug("Delete courses students");
                    $sql = "DELETE
                            FROM courses_students
                            WHERE 	course_id = {$course_id['group_id']} AND student_id = {$data->OLD->student_id}
                        ";
                    self::mysqlUpdate($sql);
                }
            }
        }

        self::printDebug("Calling recheck_tac_availability_for_group_class");
        self::mysqlUpdate("call recheck_tac_availability_for_group_class({$data->NEW->class_id})");
        return true;
    }

    static private function classesUsersAfterDelete($redis, $data)
    {

        self::printDebug("Create audit");
        $sql = "INSERT INTO classes_users_audit(
                    class_id, student_id, joined, confirmed, STATUS, ext_status, price, promotion_price, TYPE,
                    updated_monthpayment, pay_time, class_user_id, username, actiontype, modified, created, createdaudit, is_student_fault)
                VALUES ('{$data->OLD->class_id}', '{$data->OLD->student_id}', '{$data->OLD->joined}', '{$data->OLD->confirmed}', '{$data->OLD->status}', '{$data->OLD->ext_status}', '{$data->OLD->price}', '{$data->OLD->promotion_price}', '{$data->OLD->type}',
                        '{$data->OLD->updated_monthpayment}', '{$data->OLD->pay_time}', '{$data->OLD->class_user_id}', '{$data->USER}', 'Delete', '{$data->OLD->modified}', '{$data->OLD->created}', UNIX_TIMESTAMP(), '{$data->OLD->is_student_fault}')";
        self::mysqlUpdate($sql);
        //self::printDebug("$sql");

        $AddedScore      = 0;
        $NumberOfClasses = 0;
        if (($data->OLD->status >= 80 AND $data->OLD->status < 90))
        {
            $AddedScore      = -1;
            $NumberOfClasses = -1;
        }
        ELSE IF ($data->OLD->status >= 90 && !empty($data->OLD->is_student_fault))
        {
            $AddedScore = 1;
        }
        if (($AddedScore <> 0 OR $NumberOfClasses <> 0))
        {
            $sql = "UPDATE 	users_statistics
                    SET 	score = score + $AddedScore,
                        numberofclasses = numberofclasses + $NumberOfClasses
                    WHERE 	user_id = {$data->OLD->student_id}";
            self::mysqlUpdate($sql);
        }

        IF ($data->OLD->status >= 80 && $data->OLD->status < 90)
        {
            $sql = "SELECT 	c.group_id
                    FROM 	courses c INNER JOIN lessons l ON c.id = l.course_id
                          INNER JOIN classes_lessons cl ON l.id = cl.lesson_id
                    WHERE 	cl.class_id = {$data->OLD->class_id} LIMIT 1";

            $course_id = self::mysqlQuery($sql);

            if (!empty($course_id))
            {
                $sql   = "
                        SELECT 	count(*) c
                          FROM 	`courses` AS `c` INNER JOIN `lessons` AS `l` ON l.course_id = c.id
                                INNER JOIN `classes_lessons` AS `cl` ON cl.lesson_id = l.id
                                INNER JOIN `classes_users` AS `cu` ON cu.class_id = cl.class_id
                          WHERE 	c.group_id = {$course_id['group_id']} AND cu.student_id = {$data->OLD->student_id} AND cu.status >= 80  AND cu.status < 90
          ";
                $check = self::mysqlQuery($sql);

                if (empty($check) || empty($check['c']))
                {
                    $sql = "DELETE
                            FROM courses_students
                            WHERE 	course_id = {$course_id['group_id']} AND student_id = {$data->OLD->student_id}
                        ";
                    self::mysqlUpdate($sql);
                }
            }
        }

        self::printDebug("Calling recheck_tac_availability_for_group_class");
        self::mysqlUpdate("call recheck_tac_availability_for_group_class({$data->OLD->class_id})");
        return true;
    }

    // </editor-fold>

    /**
     * Get the first item in the queue and process it. If success that item will be removed.
     *
     * @return boolean <b>TRUE</b>: success process for an item.<br /><b>FALSE</b>: Not found any item in thr queue
     */
    static public function process_next_queue()
    {
        $redis = self::createRedis();
        $data  = $redis->lGetRange('myngle_queue_classes_after_change', 0, 0);

        if (empty($data))//No thing left
        {
            self::printDebug("Nothing left");
            return false;
        }
        else
        {
            $data = json_decode($data[0], FALSE);
            $exec = false;
            //
            self::printDebug("Got action $data->ACTION on database $data->DATABASE");
            //
            if (empty($data->DATABASE))
            {
                self::printDebug("Ignore this action on unknow database");
                $exec = true;
            }
            else
            {
                try
                {
                    self::setDatabase($data->DATABASE);
                    self::mysqlInit();

                    switch ($data->ACTION)
                    {
                        case 'TR_classes_INSERT':
                            $exec = self::classesAfterInsert($redis, $data);
                            break;
                        case 'TR_classes_UPDATE':
                            $exec = self::classesAfterUpdate($redis, $data);
                            break;
                        case 'TR_classes_DELETE':
                            $exec = self::classesAfterDelete($redis, $data);
                            break;

                        case 'TR_classes_users_INSERT':
                            $exec = self::classesUsersAfterInsert($redis, $data);
                            break;
                        case 'TR_classes_users_UPDATE':
                            $exec = self::classesUsersAfterUpdate($redis, $data);
                            break;
                        case 'TR_classes_users_DELETE':
                            $exec = self::classesUsersAfterDelete($redis, $data);
                            break;

                        default:
                            self::printDebug("Know idea about $data->ACTION, it will be ignored.");
                            //throw new Exception('Unknow action');
                            $exec = true; //Just delete the unknow messages to process the next one
                            break;
                    }

                    self::mysqlCommit();
                }
                catch (Exception $exc)
                {
                    self::printDebug($exc->getMessage());
                    self::printDebug($exc->getTraceAsString());
                    $exec = false;
                }
            }
        }

        if ($exec)
        {
            $done = $redis->lPop('myngle_queue_classes_after_change');
            $done = json_decode($done, FALSE);
            self::printDebug("Finish action $done->ACTION");

            // wait for 0.05 seconds
            //usleep(50000);
        }

        $data = $redis->lLen('myngle_queue_classes_after_change');

        self::printDebug("Left in queue: {$data} item" . ($data > 1 ? 's' : ''));

        $redis->close();

        return true; //return that we finished one item
    }

    /**
     * This is the callback for Redis subcribe, it will process for an item in the queue.<br >
     * It also be called on the first start, in this case it will scan and process all item stuck in the queue.
     * @param type $instance
     * @param type $channelName
     * @param type $message
     * @param type $scanAll Default is <b>FALSE</b>, if set <b>TRUE</b> it will process all item in the queue.
     */
    public function myngle_mess_classes_after_change_action($instance, $channelName, $message, $scanAll = false)
    {
        self::printDebug($channelName . "==>" . $message . " processing mode: " . ($scanAll ? 'yes' : 'no'));

        $index = 1;
        while (MyngleQueue::process_next_queue() && $scanAll)
        {
            self::printDebug("Finish no " . $index++ . "\n");
        }

        self::printDebug(__METHOD__ . " done");
    }

}

MyngleQueue::setMysqlConfig($strServer, $strUser, $strPwd);

$excer = new MyngleQueue();
$excer->myngle_mess_classes_after_change_action(null, 'First start', "scan all", true);

MyngleQueue::printDebug("Init subscriber");
//MyngleQueue::createRedis()->subscribe(['myngle_mess_classes_after_change'], 'myngle_mess_classes_after_change_action');     //Callback is the callback function name
MyngleQueue::createRedis()->subscribe(['myngle_mess_classes_after_change'], array($excer, 'myngle_mess_classes_after_change_action')); //If the callback function is the method name in the class, write this
