#!/bin/bash

echo "[INFO] Executing migrations"

# Load environment variables
env_file="/data/application/.env"
if [ ! -f "$env_file" ]; then
  echo "[ERROR] Couldn't find a .env file in /data/application/. Create it and re-run the script."
  exit 1
fi

source "$env_file"

# Wait for the MySQL service to finish initializing.
# This sucks and used to not be necessary, but I can't figure out any alternative solutions.
until mysqladmin ping -h "$MYSQL_HOST" -u "$MYSQL_ROOT_USER" -p"$MYSQL_ROOT_PASSWORD" --silent; do
    sleep 1
done

# Start a timer to track total execution time
start_time=$(date +%s%3N)

# Establish a database connection\
echo "[INFO] Establishing database connection."
mysql_cmd="mariadb -h $MYSQL_HOST -u $MYSQL_ROOT_USER -p$MYSQL_ROOT_PASSWORD"

# Ensure the application user and databases exist.
$mysql_cmd -e "
  CREATE USER IF NOT EXISTS '$MYSQL_USER'@'%' IDENTIFIED BY '$MYSQL_PASSWORD';
  ALTER USER '$MYSQL_USER'@'%' IDENTIFIED BY '$MYSQL_PASSWORD';

  CREATE DATABASE IF NOT EXISTS \`$MYSQL_GAME_DATABASE\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

  CREATE DATABASE IF NOT EXISTS \`$MYSQL_MIGRATION_DATABASE\`
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

  CREATE TABLE IF NOT EXISTS
    \`$MYSQL_MIGRATION_DATABASE\`.\`$MYSQL_MIGRATION_TABLE\` (
      id INT AUTO_INCREMENT PRIMARY KEY,
      name VARCHAR(255) NOT NULL,
      executed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    );

  GRANT SELECT, INSERT, UPDATE, DELETE
    ON \`$MYSQL_GAME_DATABASE\`.* TO '$MYSQL_USER'@'%';

  FLUSH PRIVILEGES;
"

echo "[SUCCESS] Database and application user initialization complete."

# Get all *.sql files
migrations_directory="/data/application/sql"
migrations=("$migrations_directory"/*.sql)
sorted_migrations=($(for f in "${migrations[@]}"; do echo "$f"; done | sort))

# Iterate through all *.sql files and attempt to execute them
for migration_file in "${sorted_migrations[@]}"; do
  migration_name=$(basename "$migration_file" .sql)

  if $mysql_cmd -e "SELECT * FROM $MYSQL_MIGRATION_DATABASE.$MYSQL_MIGRATION_TABLE WHERE name = '$migration_name'" | grep -q "$migration_name"; then
    echo "[INFO] Migration $migration_name already executed."
  else
    # Ensure that each file is wrapped in a transaction
    if ! grep -q "START TRANSACTION" "$migration_file"; then
      { echo "START TRANSACTION;"; cat "$migration_file"; echo "COMMIT;"; } > "$migration_file.tmp" && mv "$migration_file.tmp" "$migration_file"
    fi

    # Execute the migration using MariaDB
    if $mysql_cmd "$MYSQL_GAME_DATABASE" < "$migration_file"; then
      $mysql_cmd -e "INSERT INTO $MYSQL_MIGRATION_DATABASE.$MYSQL_MIGRATION_TABLE (name) VALUES ('$migration_name')"
      echo "[SUCCESS] Executed migration $migration_name."
    else
      echo "[ERROR] Error executing migration $migration_name. Exiting."
      exit 1
    fi
  fi
done

# Record the end time
end_time=$(date +%s%3N)

# Calculate the execution time
execution_time=$((end_time - start_time))

echo "[SUCCESS] All migrations executed successfully. ($execution_time ms)"
