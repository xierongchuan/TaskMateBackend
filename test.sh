#!/bin/bash

# TaskMate Testing Suite
# Удобный скрипт для запуска тестов различными способами

set -e

PROJECT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
cd "$PROJECT_DIR"

# Цвета для вывода
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
BLUE='\033[0;34m'
NC='\033[0m' # No Color

print_header() {
    echo -e "\n${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}"
    echo -e "${BLUE}$1${NC}"
    echo -e "${BLUE}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${NC}\n"
}

print_success() {
    echo -e "${GREEN}✓ $1${NC}"
}

print_error() {
    echo -e "${RED}✗ $1${NC}"
}

print_warning() {
    echo -e "${YELLOW}⚠ $1${NC}"
}

# Функции для запуска тестов
run_all_tests() {
    print_header "Запуск всех тестов"
    php artisan test
}

run_unit_tests() {
    print_header "Запуск Unit тестов"
    php artisan test tests/Unit
}

run_feature_tests() {
    print_header "Запуск Feature тестов"
    php artisan test tests/Feature
}

run_model_tests() {
    print_header "Запуск тестов моделей"
    php artisan test tests/Unit/Models
}

run_api_tests() {
    print_header "Запуск API тестов"
    php artisan test tests/Feature/Api
}

run_bot_tests() {
    print_header "Запуск тестов Telegram Bot"
    php artisan test tests/Feature/Bot
}

run_specific_test() {
    local test_path=$1
    print_header "Запуск теста: $test_path"
    php artisan test "$test_path"
}

run_with_coverage() {
    print_header "Запуск тестов с покрытием кода"
    php artisan test --coverage --coverage-html=coverage --min=50
    print_success "Отчет о покрытии создан в ./coverage/index.html"
}

run_parallel() {
    print_header "Параллельный запуск тестов"
    php artisan test --parallel
}

show_stats() {
    print_header "Статистика тестов"
    echo "Всего тестов:"
    php artisan test 2>&1 | grep "Tests:" || echo "Тесты еще не запущены"

    echo -e "\nРазбивка по типам:"
    echo "- Unit тестов: $(find tests/Unit -name "*.php" -type f | wc -l)"
    echo "- Feature тестов: $(find tests/Feature -name "*.php" -type f | wc -l)"
}

show_help() {
    cat << EOF
${BLUE}TaskMate Testing Suite${NC}

Использование: ./test.sh [команда]

${GREEN}Доступные команды:${NC}

  ${YELLOW}all${NC}           - Запуск всех тестов (по умолчанию)
  ${YELLOW}unit${NC}          - Запуск только Unit тестов
  ${YELLOW}feature${NC}       - Запуск только Feature тестов
  ${YELLOW}models${NC}        - Запуск тестов моделей
  ${YELLOW}api${NC}           - Запуск API тестов
  ${YELLOW}bot${NC}           - Запуск Telegram Bot тестов
  ${YELLOW}parallel${NC}      - Параллельный запуск тестов
  ${YELLOW}coverage${NC}      - Запуск с отчетом о покрытии
  ${YELLOW}stats${NC}         - Показать статистику тестов
  ${YELLOW}path:<path>${NC}   - Запустить тест по пути
  ${YELLOW}help${NC}          - Показать эту справку

${GREEN}Примеры:${NC}

  # Запустить все тесты
  ./test.sh all

  # Запустить только API тесты
  ./test.sh api

  # Запустить конкретный файл
  ./test.sh path:tests/Unit/Models/UserTest.php

  # Запустить с покрытием кода
  ./test.sh coverage

${GREEN}Быстрые команды:${NC}

  # Через composer
  composer test        # Запуск всех тестов
  composer test:unit   # Запуск Unit тестов
  composer test:api    # Запуск API тестов

EOF
}

# Основная логика
case "${1:-all}" in
    all)
        run_all_tests
        ;;
    unit)
        run_unit_tests
        ;;
    feature)
        run_feature_tests
        ;;
    models)
        run_model_tests
        ;;
    api)
        run_api_tests
        ;;
    bot)
        run_bot_tests
        ;;
    coverage)
        run_with_coverage
        ;;
    parallel)
        run_parallel
        ;;
    stats)
        show_stats
        ;;
    path:*)
        run_specific_test "${1#path:}"
        ;;
    help|-h|--help)
        show_help
        ;;
    *)
        print_error "Неизвестная команда: $1"
        echo ""
        show_help
        exit 1
        ;;
esac
