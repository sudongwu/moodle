* 修改主要在lib.php内
* 获取课程信息 get_jiaowu_course（）
* 获取课程用户信息的方法为： get_jiaowu_users（） 需要传入教务系统的课程idnumber
* 有关的课程信息会被添加到本插件创建的  enrol_jiaowu 表中

主要：

1. 生成表单的内容   lib.php 的 edit_instance_form（）
2. 在 edit_instance_validation（） 没有进行处理
3. 在 add_instance()  调用 get_jiaowu_users（）
4. 添加 刷新同步 和 查看异常 按钮的方法在 get_action_icons（）
5. 添加新的小组方法是 enrol_jiaowu_create_new_group 须传入moodle的课程id 和 课程名称 （默认是教务系统上的课程名称 且不可自定义）
6. 同步用户的方法主要是 auto_enrol_jw（） 
7. 新增 update.php文件，用来刷新同步 或者查看异常列表
