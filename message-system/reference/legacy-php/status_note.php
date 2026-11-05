<?php
// 设置log文件路径
$log_file = 'status_note.txt';

// 获取当前时间戳
$timestamp = date("Y-m-d H:i:s");

// 获取原始POST数据
$raw_post_data = file_get_contents("php://input");

// 尝试将原始POST数据解码为JSON格式以便格式化
$decoded_post_data = json_decode($raw_post_data, true);
if (json_last_error() == JSON_ERROR_NONE) {
    // 如果JSON解码成功，格式化解码后的数据
    $formatted_post_data = print_r($decoded_post_data, true);
} else {
    // 如果JSON解码失败，直接使用原始数据
    $formatted_post_data = $raw_post_data;
}

// 准备要写入的数据
$log_entry = "Timestamp: $timestamp\n";
$log_entry .= "POST Data:\n$formatted_post_data\n";
$log_entry .= "------------------------\n";

// 将数据写入log文件
file_put_contents($log_file, $log_entry, FILE_APPEND);

echo "Data has been logged.";
?>
