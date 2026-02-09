# OpenSpec для Dashboard проекта

## 🎯 Что это даёт проекту

OpenSpec - это система управления спецификациями и изменениями, которая улучшает процесс разработки.

## ✨ Основные преимущества

### 1. Структурированное планирование
Перед большими изменениями создаём **proposal** (предложение):
- Описываем ЗАЧЕМ нужно изменение
- ЧТО именно меняется
- КАКОЕ влияние на проект
- Пошаговый план реализации

### 2. Защита от поломок
- Чётко видно, какие части системы затрагиваются
- Отслеживаем breaking changes
- Проверяем конфликты между изменениями

### 3. Документация требований
Каждая функция описана через:
- **Requirements** - что система ДОЛЖНА делать
- **Scenarios** - как это работает на практике

### 4. Автоматическая валидация
```bash
openspec validate --strict  # Проверка корректности
openspec list               # Список активных изменений
openspec show [item]        # Детали изменения
```

## 📋 Трёхстадийный процесс

### Стадия 1: Создание изменения (Changes)
```bash
# Когда нужно:
# - Новые функции
# - Изменения архитектуры
# - Breaking changes
# - Оптимизации производительности

openspec list  # Смотрим текущие изменения
# Создаём новый change в openspec/changes/[название]/
```

### Стадия 2: Реализация
```bash
# 1. Читаем proposal.md
# 2. Читаем design.md (если есть)
# 3. Следуем tasks.md пошагово
# 4. Отмечаем выполненные задачи
```

### Стадия 3: Архивирование
```bash
# После деплоя
openspec archive <change-id> --yes
```

## 🚀 Быстрый старт

### Создание нового изменения

```bash
# Пример: добавление двухфакторной аутентификации

# 1. Создаём структуру
mkdir -p openspec/changes/add-two-factor-auth/specs/auth

# 2. Создаём proposal.md
cat > openspec/changes/add-two-factor-auth/proposal.md << 'EOF'
# Change: Двухфакторная аутентификация

## Why
Повысить безопасность входа в систему для администраторов.

## What Changes
- Добавление TOTP-токенов
- QR-код для настройки
- Проверка кода при входе

## Impact
- Affected specs: auth
- Affected code: auth.php, login.php
EOF

# 3. Создаём tasks.md
cat > openspec/changes/add-two-factor-auth/tasks.md << 'EOF'
## 1. Implementation
- [ ] 1.1 Добавить поле two_fa_secret в users
- [ ] 1.2 Создать функцию генерации TOTP
- [ ] 1.3 Добавить проверку кода при логине
- [ ] 1.4 Создать UI для настройки 2FA
EOF

# 4. Создаём спецификацию
cat > openspec/changes/add-two-factor-auth/specs/auth/spec.md << 'EOF'
## ADDED Requirements
### Requirement: Two-Factor Authentication
Система ДОЛЖНА поддерживать двухфакторную аутентификацию через TOTP.

#### Scenario: Успешная аутентификация с 2FA
- **WHEN** пользователь вводит правильный пароль и код 2FA
- **THEN** система предоставляет доступ
EOF

# 5. Валидируем
openspec validate add-two-factor-auth --strict
```

## 📝 Когда создавать proposal

### ✅ Создавать proposal для:
- Новых функций (новые страницы, API endpoints)
- Изменений схемы БД
- Рефакторинга архитектуры
- Оптимизаций производительности
- Изменений безопасности

### ❌ НЕ нужен proposal для:
- Исправления багов (возврат к правильному поведению)
- Опечаток, форматирования
- Обновления комментариев
- Мелких UI улучшений
- Добавления логов

## 🔍 Naming conventions

### Для changes (изменений):
- `add-[feature]` - новая функция
- `update-[feature]` - изменение существующей
- `remove-[feature]` - удаление функции
- `refactor-[component]` - рефакторинг

**Примеры:**
- `add-rate-limiting`
- `update-export-performance`
- `refactor-database-queries`
- `remove-legacy-code`

### Для capabilities (возможностей):
- `user-auth` - аутентификация пользователей
- `account-management` - управление аккаунтами
- `data-export` - экспорт данных

## 📁 Структура OpenSpec

```
openspec/
├── project.md              # Контекст проекта (уже заполнен!)
├── AGENTS.md              # Инструкции для AI
├── specs/                 # Текущие спецификации (что УЖЕ реализовано)
│   └── [capability]/
│       └── spec.md
├── changes/               # Предложения изменений (что БУДЕТ изменено)
│   ├── [change-name]/
│   │   ├── proposal.md
│   │   ├── tasks.md
│   │   ├── design.md (опционально)
│   │   └── specs/
│   │       └── [capability]/
│   │           └── spec.md
│   └── archive/          # Завершённые изменения
```

## 🛠 Полезные команды

```bash
# Просмотр
openspec list                       # Активные изменения
openspec list --specs               # Список спецификаций
openspec show [item]                # Детали элемента

# Валидация
openspec validate                   # Проверить всё
openspec validate [change] --strict # Проверить конкретное изменение

# Архивирование
openspec archive <change-id> --yes  # Заархивировать после деплоя

# Отладка
openspec show [change] --json --deltas-only
```

## 💡 Пример workflow

### Сценарий: Добавление экспорта в Excel

```bash
# 1. Проверяем текущее состояние
openspec list
openspec list --specs

# 2. Создаём change
mkdir -p openspec/changes/add-excel-export/specs/data-export

# 3. Пишем proposal.md
echo "# Change: Экспорт в Excel" > openspec/changes/add-excel-export/proposal.md
# ... заполняем детали ...

# 4. Пишем tasks.md
echo "## 1. Implementation" > openspec/changes/add-excel-export/tasks.md
# ... заполняем задачи ...

# 5. Создаём спецификацию
cat > openspec/changes/add-excel-export/specs/data-export/spec.md << 'EOF'
## ADDED Requirements
### Requirement: Excel Export
Система ДОЛЖНА экспортировать данные в формат XLSX.

#### Scenario: Успешный экспорт
- **WHEN** пользователь нажимает "Экспорт в Excel"
- **THEN** система создаёт файл .xlsx со всеми данными
EOF

# 6. Валидируем
openspec validate add-excel-export --strict

# 7. Реализуем (AI помогает, следуя tasks.md)
# ... разработка ...

# 8. После деплоя архивируем
openspec archive add-excel-export --yes
```

## 🎓 Дополнительная информация

### Формат Requirements

```markdown
## ADDED Requirements
### Requirement: Название требования
Описание того, что система ДОЛЖНА (SHALL/MUST) делать.

#### Scenario: Название сценария
- **WHEN** условие/действие
- **THEN** ожидаемый результат
```

### Формат операций

- `## ADDED Requirements` - новые возможности
- `## MODIFIED Requirements` - изменения поведения (полный текст требования)
- `## REMOVED Requirements` - удаляемые функции
- `## RENAMED Requirements` - изменение названия

### Важно!
- Каждое требование ДОЛЖНО иметь минимум один `#### Scenario:`
- Сценарии должны начинаться с 4 решёток `####`
- Используйте `SHALL` или `MUST` для нормативных требований

## 🔗 Связь с разработкой

### Перед любой задачей AI будет:
1. Читать `openspec/project.md` для понимания контекста
2. Проверять `openspec/list` на активные изменения
3. Создавать proposal для крупных изменений
4. Валидировать изменения перед реализацией

### Вы получаете:
- ✅ Прозрачность: всегда видно, что меняется
- ✅ Безопасность: проверка конфликтов и breaking changes
- ✅ История: все изменения документированы
- ✅ Согласованность: единый подход к разработке

## 📞 Поддержка

Если что-то непонятно:
1. Читайте `openspec/AGENTS.md` - подробные инструкции
2. Смотрите примеры в `changes/archive/`
3. Запускайте `openspec validate --strict` для диагностики

---

**OpenSpec теперь активирован для проекта Dashboard!** 🎉

Все крупные изменения будут проходить через процесс спецификаций, что сделает разработку более предсказуемой и безопасной.








