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
To create default admin user and demo data:
```sh
docker compose exec src_telegram_bot_api php artisan db:seed
```

This will create:
- **1 Admin user** (owner)
- **3 Dealerships** with their own managers and employees
- **Tasks with assignments** for each dealership
- **Important links** for each dealership

**Default credentials:**
- Admin: `admin` / `password`

**Demo Dealerships:**
- **Avto Salon Center**: Manager `manager1`, Employees `emp1_1`, `emp1_2`, `emp1_3`
- **Avto Salon Sever**: Manager `manager2`, Employees `emp2_1`, `emp2_2`, `emp2_3`
- **Auto Salon Lux**: Manager `manager3`, Employees `emp3_1`, `emp3_2`, `emp3_3`

**All user passwords:** `password`

**Tasks created per dealership:**
- 6 individual tasks (2 per employee)
- 2 group tasks (assigned to all employees)
- Total: 8 tasks with 12 assignments per dealership

