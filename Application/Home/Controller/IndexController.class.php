<?php

namespace Home\Controller;

use Think\Controller;
use Think\Model;
use Think\Upload;
use Think\Page;

class IndexController extends Controller {
    public function displayIndex($where, $action_name, $title, $display_page) {
        $data_sum = M("data")->where($where)->count();
        $page = new Page($data_sum, 15);
        $file_list = M("data")->where($where)->limit($page->firstRow, $page->listRows)->order("id desc")->select();
        $check_in = $this->checkInCheck($where['img_user']);
        $this->assign("file_list", $file_list);
        $this->assign("page", $page->show());
        $this->assign("action_name", $action_name);
        $this->assign("title", $title);
        $this->assign("check_in", $check_in);
        $this->display($display_page);
    }

    public function index() {
        $login_status = $this->loginCheck();
        $where = array("img_user" => $login_status['user_id']);
        $this->displayIndex($where, "index", "首页", "Index/index");
    }

    public function publicImg() {
        $login_status = $this->loginCheck();
        $where = array(
            "img_user" => array("neq", $login_status['user_id']),
            "img_status" => 0
        );
        $this->displayIndex($where, "publicImg", "共享文件", "Index/index");
    }

    public function register() {
        $post_data = I("post.");

        $insert_data = array(
            "username" => md5($post_data['username']),
            "password" => md5($post_data['password']),
            "token" => md5($post_data['username'] . get_client_ip() . NOW_TIME),
            "login_ip" => get_client_ip(),
            "login_time" => NOW_TIME,
            "status" => 1,
        );
        $insert_result = M("user")->add($insert_data);

        if ($insert_result != false) $this->ajaxReturn(array("code" => 200, "msg" => "success"));
    }

    public function login() {
        $get_data = I("get.");

        $where_data["username"] = md5($get_data['username']);
        $select_result = M("user")->where($where_data)->find();
        if ($select_result) {
            $where_data["password"] = md5($get_data['password']);
            $select_result = M("user")->where($where_data)->find();
            if ($select_result) {
                cookie("user", json_encode(array("username" => $get_data['username'], "token" => $select_result['token'])));
                $update_data = array("login_time" => time());
                if ($select_result['login_ip'] != get_client_ip()) $update_data['login_ip'] = get_client_ip();
                M("user")->where(array("id" => $select_result['id']))->save($update_data);
                $this->redirect("Index/index");
            } else {
                $this->ajaxReturn(array("code" => 401, "msg" => "password error"));
            }
        } else {
            $this->ajaxReturn(array("code" => 401, "msg" => "not found user"));
        }
    }

    public function upload() {
        $img_upload_result = $this->uploadFileHandle();
        if ($img_upload_result["code"] == 0) {
            $this->error($img_upload_result["msg"]);
        } else {
            $insert_data = array(
                "img_user" => 1,
                "img_path" => $img_upload_result["img_path"],
                "img_status" => I("post.img_status"),
                "img_upload_time" => NOW_TIME,
                "img_upload_ip" => get_client_ip()
            );
            $insert_result = M("data")->add($insert_data);
            if ($insert_result != false) $this->success("数据保存成功");
            else $this->error("数据保存失败 result: " . $insert_result);
        }
    }

    public function uploadFileHandle() {
        $rootPath = "/Upload/";
        $config = array(
            'maxSize' => 5242880,
            'rootPath' => '.' . $rootPath,
            'savePath' => '',
            'saveName' => array('uniqid', ''),
            'exts' => array('jpg', 'gif', 'png', 'jpeg'),
            'autoSub' => false,
        );
        $upload = new Upload($config);
        $info = $upload->uploadOne($_FILES['photo']);
        if (!$info) $result = array("code" => 0, "msg" => $upload->getError());
        else $result = array("code" => 1, "img_path" => $rootPath . $info['savename']);
        return $result;
    }

    public function loginCheck() {
        $cookie_data = json_decode(cookie("user"), true);
        $where_data = array(
            "username" => md5($cookie_data['username']),
            "token" => $cookie_data['token'],
        );
        $user_info = M("user")->where($where_data)->find();
        if ($user_info) {
            return array("code" => 1, "user_id" => $user_info['id']);
        } else {
            $this->ajaxReturn(array("msg" => "Please login"));
            return array("code" => 0);
        }
    }

    public function checkInCheck($user_id) {
        $check_in = M("check_in")->where(array("user_id" => $user_id, "check_in_time" => array(array('egt', strtotime(date("Y-m-d 0:0:0"))), array('elt', strtotime(date("Y-m-d 23:59:59"))), 'and')))->find();
        return ($check_in) ? 1 : 0;
    }

    public function updateStatus() {
        $update_result = M("data")->where(array("id" => I("post.id")))->setField('img_status', (I("post.status") == "1") ? "0" : "1");
        $this->ajaxReturn($update_result);
    }

    public function deleteImg() {
        $delete_data = M("data")->where(array("id" => I("post.id")))->find();
        unlink("." . $delete_data['img_path']);
        $delete_result = M("data")->where(array("id" => I("post.id")))->delete();
        $this->ajaxReturn($delete_result);
    }

    public function lucky() {
        $login_status = $this->loginCheck();
        $lucky_log_has = M("lucky_log")->where(array("user_id" => $login_status['user_id']))->find();
        if ($lucky_log_has) {
            $lucky_log_time = $lucky_log_has['last_time'] >= strtotime(date("Y-m-d 00:00:00")) && $lucky_log_has['last_time'] <= strtotime(date("Y-m-d 23:59:59"));
            if ($lucky_log_has['lucky_sum'] > 10 || $lucky_log_time || $lucky_log_has['last_time'] >= time() + (30 * 60)) $query = M("data")->where(array("id" => $lucky_log_has['lucky_id']))->select();
        }

        if (!isset($query)) {
            $query = $this->lucky_random();
            if ($lucky_log_has) {
                $lucky_log_has['lucky_id'] = $query[0]['id'];
                $lucky_log_has['last_time'] = time();
                $lucky_log_has['lucky_sum'] += 1;
                $lucky_result = M("lucky_log")->save($lucky_log_has);
            } else {
                $lucky_result = M("lucky_log")->add(array(
                    "user_id" => $login_status['user_id'],
                    "lucky_id" => $query[0]['id'],
                    "last_time" => time(),
                    "lucky_sum" => 1
                ));
            }
            if (!$lucky_result) $this->error("出错了", "Index/index");
        }

        $check_in = $this->checkInCheck($login_status['user_id']);
        $this->assign("file_list", $query);
        $this->assign("action_name", "lucky");
        $this->assign("title", "试试手气");
        $this->assign("check_in", $check_in);
        $this->display("Index/index");

    }

    public function lucky_random() {
        $between = M("data")->field("MIN(id) as min, MAX(id) as max, COUNT(1) as count")->find();
        $where = array("id" => rand($between['min'], $between['max']), "img_status" => 0);
        $roll = rand(0, 100);
        if ($roll <= 5) $where['img_status'] = 1;
        $query = M("data")->where($where)->select();
        return (empty($query)) ? $this->lucky_random() : $query;
    }

    public function checkIn() {
        $login_status = $this->loginCheck();
        if ($login_status['code'] == 1) {
            $where = array(
                "user_id" => $login_status['user_id'],
                "check_in_time" => array(array('egt', strtotime(date("Y-m-d 0:0:0"))), array('elt', strtotime(date("Y-m-d 23:59:59"))), 'and')
            );
            $check_in_log = M("check_in")->where($where)->find();
            if (empty($check_in_log)) {
                $insert_data = array(
                    "user_id" => $login_status['user_id'],
                    "check_in_time" => NOW_TIME
                );
                $insert_result = M("check_in")->data($insert_data)->add();
                if ($insert_result != false) $check_in_result = array("code" => 1, "msg" => "check in success");
                else $check_in_result = array("code" => 0, "msg" => "check in error");
            } else {
                $check_in_result = array("code" => 2, "msg" => "checked in");
            }
            $this->ajaxReturn($check_in_result);
        }
    }
}