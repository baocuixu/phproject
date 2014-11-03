<?php

namespace Model;

class User extends \Model {

	protected $_table_name = "user";

	/**
	 * Load currently logged in user, if any
	 * @return mixed
	 */
	public function loadCurrent() {
		$f3 = \Base::instance();
		if($user_id = $f3->get("SESSION.phproject_user_id")) {
			$this->load(array("id = ? AND deleted_date IS NULL", $user_id));
			if($this->id) {
				$f3->set("user", $this->cast());
				$f3->set("user_obj", $this);
			}
		}
		return $this;
	}

	/**
	 * Get path to user's avatar or gravatar
	 * @param  integer $size
	 * @return string|bool
	 */
	public function avatar($size = 80) {
		if(!$this->get("id")) {
			return false;
		}
		if($this->get("avatar_filename") && is_file("uploads/avatars/" . $this->get("avatar_filename"))) {
			return "/avatar/$size-" . $this->get("id") . ".png";
		}
		return \Helper\View::instance()->gravatar($this->get("email"), $size);
	}

	/**
	 * Load all active users
	 * @return array
	 */
	public function getAll() {
		return $this->find("deleted_date IS NULL AND role != 'group'", array("order" => "name ASC"));
	}

	/**
	 * Load all active groups
	 * @return array
	 */
	public function getAllGroups() {
		return $this->find("deleted_date IS NULL AND role = 'group'", array("order" => "name ASC"));
	}

	/**
	 * Send an email alert with issues due on the given date
	 * @param  string $date
	 * @return bool
	 */
	public function sendDueAlert($date = '') {
		if(!$this->get("id")) {
			return false;
		}

		if(!$date) {
			$date = date("Y-m-d", \Helper\View::instance()->utc2local());
		}

		$issue = new \Model\Issue;
		$issues = $issue->find(array("due_date = ? AND owner_id = ? AND closed_date IS NULL AND deleted_date IS NULL", $date, $this->get("id")), array("order" => "priority DESC"));

		if($issues) {
			$notif = new \Helper\Notification;
			return $notif->user_due_issues($this, $issues);
		} else {
			return false;
		}
	}

	/**
	 * Get user statistics
	 * @param  int $time  The lower limit on timestamps for stats collection
	 * @return array
	 */
	public function stats($time = 0) {
		$db = \Base::instance()->get("db.instance");
		if(!$time) {
			$time = strtotime("-2 weeks");
		}

		$result = array();
		$result["spent"] = $db->exec(
			"SELECT DATE(u.created_date) AS `date`, SUM(f.new_value - f.old_value) AS `val`
			FROM issue_update u
			JOIN issue_update_field f ON u.id = f.issue_update_id AND f.field = 'hours_spent'
			WHERE u.user_id = :user AND u.created_date > :date
			GROUP BY DATE(u.created_date)",
			array("user" => $this->get("id"), "date" => date("Y-m-d H:i:s", $time))
		);
		$result["closed"] = $db->exec(
			"SELECT DATE(i.closed_date) AS `date`, COUNT(*) AS `val`
			FROM issue i
			WHERE i.owner_id = :user AND i.closed_date > :date
			GROUP BY DATE(i.closed_date)",
			array("user" => $this->get("id"), "date" => date("Y-m-d H:i:s", $time))
		);
		$result["created"] = $db->exec(
			"SELECT DATE(i.created_date) AS `date`, COUNT(*) AS `val`
			FROM issue i
			WHERE i.author_id = :user AND i.created_date > :date
			GROUP BY DATE(i.created_date)",
			array("user" => $this->get("id"), "date" => date("Y-m-d H:i:s", $time))
		);

		$dates = $this->_createDateRangeArray(date("Y-m-d", $time), date("Y-m-d"));
		$return = array(
			"labels" => array(),
			"spent" => array(),
			"closed" => array(),
			"created" => array()
		);
		foreach($dates as $date) {
			$return["labels"][] = date("M j", strtotime($date));
			foreach($result["spent"] as $r) {
				if($r["date"] == $date) {
					$return["spent"][$date] = floatval($r["val"]);
				} elseif(!isset($return["spent"][$date])) {
					$return["spent"][$date] = 0;
				}
			}
			foreach($result["closed"] as $r) {
				if($r["date"] == $date) {
					$return["closed"][$date] = intval($r["val"]);
				} elseif(!isset($return["closed"][$date])) {
					$return["closed"][$date] = 0;
				}
			}
			foreach($result["created"] as $r) {
				if($r["date"] == $date) {
					$return["created"][$date] = intval($r["val"]);
				} elseif(!isset($return["created"][$date])) {
					$return["created"][$date] = 0;
				}
			}
		}

		return $return;
	}

}

