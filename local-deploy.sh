#!/bin/bash

set -e  # 遇錯即停

echo "關閉容器、重新建立image並啟動容器"
docker-compose down
docker-compose build
docker-compose up -d
echo ""

echo "等待資料庫啟動中..."
until docker-compose exec app php -r "new PDO('mysql:host=db;port=3306', 'root', 'root');" >/dev/null 2>&1; do
  echo "MySQL 還沒好，等待中..."
  sleep 2
done
echo "MySQL 已啟動！"
echo ""

echo "清除 Laravel 快取設定"
docker-compose exec app php artisan config:clear
docker-compose exec app php artisan cache:clear
echo ""

echo "執行 migrate"
docker-compose exec app php artisan migrate --force
echo ""

# 檢查 Cloudflared 是否已在背景執行
if pgrep -f "cloudflared tunnel.*my-line-bot" > /dev/null; then
  echo "Cloudflared tunnel 已在執行中，跳過啟動。"
  echo ""
else
  echo "Cloudflared 啟動中"
  nohup cloudflared tunnel --config ~/.cloudflared/my-line-bot.yml run my-line-bot > cloudflared.log 2>&1 &
  echo "Cloudflared tunnel 已啟動，log 記錄於 cloudflared.log"
  echo ""
fi

echo "本地開發環境已就緒！"
