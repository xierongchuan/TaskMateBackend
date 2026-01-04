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
To creates default admin user and demo data:
```sh
docker compose exec src_telegram_bot_api php artisan db:seed
```
Default credentials:
- Login: `admin`
- Password: `password`

Demo Dealers:
- **Avto Salon Center**: Manager `manager1`, Employees `emp1_1`, `emp1_2`...
- **Avto Salon Sever**: Manager `manager2`, Employees `emp2_1`...
- **Auto Salon Lux**: Manager `manager3`, Employees `emp3_1`...
All passwords are `password`.
