<?php
// 设置log文件路径
$log_file = 'sms_status_report.txt';

// 获取当前时间戳
$timestamp = date("Y-m-d H:i:s");

// 获取原始POST数据
$raw_post_data = file_get_contents("php://input");

// 准备要写入的数据
$log_entry = "Timestamp: $timestamp\n";
$log_entry .= "POST Data:\n$raw_post_data\n";
$log_entry .= "------------------------\n";

// 将数据写入log文件
file_put_contents($log_file, $log_entry, FILE_APPEND);

echo "Data has been logged.";
?>

