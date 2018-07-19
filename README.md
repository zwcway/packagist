# packagist
Packagist Of Composer

由于众所周知的原因，国内使用 composer 一直是一件痛苦的事情。

使用该脚本可以自己搭建一个元数据的全量镜像，定时更新即可，体验飞一样的感觉。

截止2018年，大概会占用硬盘空间3G

下载最终文件存放在 public 目录

# 使用方法

首先需要运行一次 composer

```
composer dump-autoload
```

然后执行命令

```
php packagist da
```

## 计划任务
配置 /etc/cron.d/packagist 定时更新

```
# 仅更新 packages.json
*/5 * * * * /path/to/packagist dp

# 仅更新 packages.json 和包的索引
*/5 * * * * /path/to/packagist di

# 更新整个镜像
*/5 * * * * /path/to/packagist da
```

## 站点配置

将站点根目录指向为 public 目录

### 配置 rewrite

Nginx 的配置为

```
rewrite ^/p/((\w)(\w)[-_\w]+/.+)$  /p/$2/$3/$1 last;
```

Apache 的配置见 public/.htaccess，具体内容如下

```
RewriteEngine On
RewriteCond %{REQUEST_FILENAME} !-f
RewriteRule ^((\w)(\w)[-_\w]+/.+)$ $2/$3/$1 [L]
```

## 配置 composer

```
composer config [--global] repo.packagist composer https?://yourwebsite
```

# 其他
基于 [Garveen/Imagist](https://github.com/Garveen/Imagist) 