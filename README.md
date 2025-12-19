## RIMS Project Setup Guide

Follow these steps to set up and run the RIMS Laravel application:

### 1. Copy Environment File

Copy the example environment file and rename it to `.env`:

```sh
cp .env.example .env
```

### 2. Install Composer Dependencies

Install all PHP dependencies using Composer:

```sh
composer install
```

### 3. Run Database Migrations

Run the database migrations to set up the required tables:

```sh
php artisan migrate
```

### 4. Generate VAPID Web Push Keys

Generate VAPID keys for web push notifications:

```sh
php artisan webpush:vapid
```

Copy the generated keys to your `.env` file as instructed by the command output.

### 5. Configure Telegram Push Notification

To enable Telegram push notifications:

1. Create a Telegram bot via [BotFather](https://t.me/BotFather) and obtain the bot token.
2. Set the following variables in your `.env` file:

	```env
	TELEGRAM_BOT_TOKEN=your_bot_token_here
	TELEGRAM_BOT_USERNAME=your_bot_username_here
	```
3. Optionally, configure chat IDs or other settings as required by your implementation.

---



## Requirements

- Redis server must be installed and running. This application uses Redis for session, queue, and cache drivers. Ensure your Redis server is accessible and configured in your `.env` file.

For more details, refer to project-specific guides or documentation.


## License

This project is open source. See the [LICENSE](LICENSE) file for details.
