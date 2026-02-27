CREATE DATABASE IF NOT EXISTS okulus_feldsher;
USE okulus_feldsher;

-- Таблица пользователей
CREATE TABLE users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(100) NOT NULL,
    password VARCHAR(255) NOT NULL,
    full_name VARCHAR(150) NOT NULL,
    email VARCHAR(100) UNIQUE,
    role ENUM('patient', 'ophthalmologist', 'surgeon') DEFAULT 'patient',
    district VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Таблица пациентов
CREATE TABLE patients (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT,
    district VARCHAR(100),
    doctor_id INT,
    surgeon_id INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE SET NULL,
    FOREIGN KEY (surgeon_id) REFERENCES users(id) ON DELETE SET NULL
);

-- Таблица заболеваний
CREATE TABLE diseases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(255) NOT NULL,
    description TEXT
);

-- Таблица операций
CREATE TABLE surgeries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    disease_id INT,
    surgery_type VARCHAR(255),
    status ENUM('new', 'preparation', 'review', 'approved', 'rejected') DEFAULT 'new',
    surgery_date DATE,
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (disease_id) REFERENCES diseases(id) ON DELETE SET NULL
);

-- Таблица анализов
CREATE TABLE tests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    surgery_id INT NOT NULL,
    test_name VARCHAR(255),
    status ENUM('pending', 'uploaded', 'approved', 'rejected') DEFAULT 'pending',
    file_path VARCHAR(500),
    uploaded_at TIMESTAMP NULL,
    FOREIGN KEY (surgery_id) REFERENCES surgeries(id) ON DELETE CASCADE
);

-- Таблица записей на приём
CREATE TABLE appointments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    patient_id INT NOT NULL,
    doctor_id INT NOT NULL,
    appointment_date DATETIME,
    status ENUM('pending', 'confirmed', 'completed', 'cancelled') DEFAULT 'pending',
    notes TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (patient_id) REFERENCES patients(id) ON DELETE CASCADE,
    FOREIGN KEY (doctor_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Вставка начальных данных
INSERT INTO users (username, password, full_name, email, role, district) VALUES
('ivanov_doctor', '$2y$10$YourHashedPasswordHere', 'Смирнова А.В.', 'doctor@okulus.ru', 'ophthalmologist', 'Кировский'),
('petrov_surgeon', '$2y$10$YourHashedPasswordHere', 'Петров И.И.', 'surgeon@okulus.ru', 'surgeon', 'Областной центр'),
('patient1', '$2y$10$YourHashedPasswordHere', 'Иванов Пётр Сергеевич', 'patient1@mail.ru', 'patient', 'Кировский'),
('patient2', '$2y$10$YourHashedPasswordHere', 'Кузнецова Ольга Дмитриевна', 'patient2@mail.ru', 'patient', 'Первомайский'),
('patient3', '$2y$10$YourHashedPasswordHere', 'Сидоров Алексей Николаевич', 'patient3@mail.ru', 'patient', 'Октябрьский'),
('patient4', '$2y$10$YourHashedPasswordHere', 'Морозов Виктор Андреевич', 'patient4@mail.ru', 'patient', 'Свердловский');

INSERT INTO diseases (name, description) VALUES
('Катаракта правого глаза', 'Помутнение хрусталика правого глаза'),
('Катаракта левого глаза', 'Помутнение хрусталика левого глаза'),
('Катаракта обоих глаз', 'Двустороннее помутнение хрусталика'),
('Отслойка сетчатки', 'Отделение сетчатки от сосудистой оболочки'),
('Глаукома II стадии', 'Повышенное внутриглазное давление');

INSERT INTO patients (user_id, district, doctor_id, surgeon_id) VALUES
(3, 'Кировский', 1, 2),
(4, 'Первомайский', 1, 2),
(5, 'Октябрьский', 1, 2),
(6, 'Свердловский', 1, 2);

INSERT INTO surgeries (patient_id, disease_id, surgery_type, status) VALUES
(1, 1, 'Факоэмульсификация', 'preparation'),
(2, 4, 'Витрэктомия', 'preparation'),
(3, 3, 'Факоэмульсификация', 'approved');

INSERT INTO tests (surgery_id, test_name, status) VALUES
(1, 'Общий анализ крови', 'uploaded'),
(1, 'Биохимия крови', 'uploaded'),
(1, 'ЭКГ', 'pending'),
(1, 'Осмотр терапевта', 'pending'),
(1, 'Биометрия глаза (IOL Master)', 'approved'),
(2, 'Общий анализ крови', 'uploaded'),
(2, 'Биохимия крови', 'uploaded'),
(2, 'ЭКГ', 'uploaded'),
(2, 'Осмотр терапевта', 'pending'),
(3, 'Общий анализ крови', 'uploaded'),
(3, 'Биохимия крови', 'uploaded'),
(3, 'ЭКГ', 'uploaded'),
(3, 'Осмотр терапевта', 'uploaded'),
(3, 'Биометрия глаза (IOL Master)', 'uploaded');