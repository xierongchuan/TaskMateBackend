# TaskMateTelegramBot

### Install Env
```sh
composer i
```

### Run
```sh
docker-compose up -d --build
```

### Requires
* php8.4.10

License: Proprietary License

### Seeding
To creates default admin user:
```sh
docker compose exec src_telegram_bot_api php artisan db:seed
```
Default credentials:
- Login: `admin`
- Password: `password`
