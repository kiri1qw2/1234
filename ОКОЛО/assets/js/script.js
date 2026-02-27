// Анимации и интерактивность
document.addEventListener('DOMContentLoaded', function() {
    // Подсветка активной навигации
    const currentLocation = window.location.pathname;
    const navLinks = document.querySelectorAll('.nav-links a');
    
    navLinks.forEach(link => {
        if (link.getAttribute('href') === currentLocation.split('/').pop()) {
            link.classList.add('active');
        }
    });

    // Анимация прогресс-баров
    const progressBars = document.querySelectorAll('.progress-fill');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 100);
    });

    // Валидация форм
    const forms = document.querySelectorAll('form');
    forms.forEach(form => {
        form.addEventListener('submit', function(e) {
            if (!validateForm(this)) {
                e.preventDefault();
            }
        });
    });

    // Анимация появления карточек при скролле
    const cards = document.querySelectorAll('.patient-card, .stat-card');
    const observer = new IntersectionObserver((entries) => {
        entries.forEach(entry => {
            if (entry.isIntersecting) {
                entry.target.style.opacity = '1';
                entry.target.style.transform = 'translateY(0)';
            }
        });
    }, { threshold: 0.1 });

    cards.forEach(card => {
        card.style.opacity = '0';
        card.style.transform = 'translateY(20px)';
        card.style.transition = 'all 0.5s ease-out';
        observer.observe(card);
    });

    // Поиск пациентов в реальном времени
    const searchInput = document.getElementById('patientSearch');
    if (searchInput) {
        searchInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const patientCards = document.querySelectorAll('.patient-card');
            
            patientCards.forEach(card => {
                const patientName = card.querySelector('.patient-name')?.textContent.toLowerCase() || '';
                const patientDistrict = card.querySelector('.patient-district')?.textContent.toLowerCase() || '';
                
                if (patientName.includes(searchTerm) || patientDistrict.includes(searchTerm)) {
                    card.style.display = 'block';
                    card.style.animation = 'fadeIn 0.5s ease-in';
                } else {
                    card.style.display = 'none';
                }
            });
        });
    }

    // Загрузка анализов (имитация)
    const uploadButtons = document.querySelectorAll('.upload-test');
    uploadButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            e.preventDefault();
            const testId = this.dataset.testId;
            simulateUpload(testId);
        });
    });
});

function validateForm(form) {
    let isValid = true;
    const inputs = form.querySelectorAll('input[required], select[required]');
    
    inputs.forEach(input => {
        if (!input.value.trim()) {
            input.style.borderColor = '#dc3545';
            isValid = false;
            
            // Добавляем сообщение об ошибке
            let errorMsg = input.nextElementSibling;
            if (!errorMsg || !errorMsg.classList.contains('error-message')) {
                errorMsg = document.createElement('span');
                errorMsg.classList.add('error-message');
                errorMsg.style.color = '#dc3545';
                errorMsg.style.fontSize = '0.8rem';
                errorMsg.style.marginTop = '0.3rem';
                errorMsg.style.display = 'block';
                input.parentNode.appendChild(errorMsg);
            }
            errorMsg.textContent = 'Это поле обязательно для заполнения';
        } else {
            input.style.borderColor = '#e0e0e0';
            const errorMsg = input.nextElementSibling;
            if (errorMsg && errorMsg.classList.contains('error-message')) {
                errorMsg.remove();
            }
        }
    });
    
    return isValid;
}

function simulateUpload(testId) {
    const button = document.querySelector(`[data-test-id="${testId}"]`);
    if (button) {
        button.textContent = 'Загрузка...';
        button.disabled = true;
        
        setTimeout(() => {
            button.textContent = 'Загружено ✓';
            button.style.background = '#28a745';
            
            // Обновляем статус
            const testItem = button.closest('.test-item');
            if (testItem) {
                const statusBadge = testItem.querySelector('.test-status');
                if (statusBadge) {
                    statusBadge.className = 'test-status uploaded';
                    statusBadge.textContent = 'Загружен';
                }
            }
        }, 2000);
    }
}

// График операций
function loadSchedule(month, year) {
    fetch(`api/get_schedule.php?month=${month}&year=${year}`)
        .then(response => response.json())
        .then(data => {
            const scheduleContainer = document.getElementById('schedule');
            if (scheduleContainer) {
                scheduleContainer.innerHTML = '';
                data.forEach(appointment => {
                    const card = createAppointmentCard(appointment);
                    scheduleContainer.appendChild(card);
                });
            }
        })
        .catch(error => console.error('Error:', error));
}

function createAppointmentCard(appointment) {
    const card = document.createElement('div');
    card.className = 'patient-card';
    card.innerHTML = `
        <div class="patient-header">
            <span class="patient-name">${appointment.patient_name}</span>
            <span class="patient-district">${appointment.district}</span>
        </div>
        <div class="patient-diagnosis">${appointment.diagnosis}</div>
        <div class="analysis-progress">
            <div class="progress-label">
                <span>Анализы: ${appointment.tests_completed}/${appointment.tests_total}</span>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" style="width: ${(appointment.tests_completed/appointment.tests_total)*100}%"></div>
            </div>
        </div>
        <span class="surgery-type">${appointment.surgery_type}</span>
        <span class="status-badge status-${appointment.status}">${appointment.status}</span>
        <div class="appointment-date">
            Дата операции: ${appointment.surgery_date || 'Не назначена'}
        </div>
    `;
    return card;
}

// Уведомления
function showNotification(message, type = 'info') {
    const notification = document.createElement('div');
    notification.className = `notification notification-${type}`;
    notification.textContent = message;
    notification.style.cssText = `
        position: fixed;
        top: 20px;
        right: 20px;
        padding: 1rem 2rem;
        background: ${type === 'success' ? '#28a745' : type === 'error' ? '#dc3545' : '#17a2b8'};
        color: white;
        border-radius: 10px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        z-index: 1000;
        animation: slideIn 0.3s ease-out;
    `;
    
    document.body.appendChild(notification);
    
    setTimeout(() => {
        notification.remove();
    }, 3000);
}