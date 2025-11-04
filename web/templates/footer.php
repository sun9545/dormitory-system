        </div> <!-- 结束 .content 容器 -->
    </div> <!-- 结束 .d-flex 容器 -->

    <!-- 返回顶部按钮 -->
    <a href="#" class="btn btn-primary rounded-circle position-fixed" id="back-to-top" style="bottom: 25px; right: 25px; display: none; width: 45px; height: 45px; z-index: 1000;">
        <i class="bi bi-arrow-up" style="font-size: 1.5rem; line-height: 1.5;"></i>
    </a>

    <!-- 页脚 -->
    <footer class="bg-light py-3 mt-auto" style="margin-left: var(--sidebar-width);">
        <div class="container text-center">
            <p class="mb-0">&copy; <?php echo date('Y'); ?> <?php echo SITE_NAME; ?>. 保留所有权利。</p>
        </div>
    </footer>

    <!-- JavaScript 库 -->
    <script src="<?php echo BASE_URL; ?>/assets/js/bootstrap.bundle.min.js"></script>
    <script src="<?php echo BASE_URL; ?>/assets/js/jquery.min.js"></script>
    
    <script>
    // 通用函数
    
    // 初始化提示工具
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // 格式化日期时间
    function formatDateTime(dateString) {
        if (!dateString) return '';
        var date = new Date(dateString);
        return date.getFullYear() + '-' + 
               ('0' + (date.getMonth() + 1)).slice(-2) + '-' + 
               ('0' + date.getDate()).slice(-2) + ' ' + 
               ('0' + date.getHours()).slice(-2) + ':' + 
               ('0' + date.getMinutes()).slice(-2) + ':' + 
               ('0' + date.getSeconds()).slice(-2);
    }
    
    // 状态徽章
    function getStatusBadge(status) {
        let badgeClass = '';
        switch (status) {
            case '在寝':
                badgeClass = 'bg-success';
                break;
            case '离寝':
                badgeClass = 'bg-danger';
                break;
            case '请假':
                badgeClass = 'bg-warning';
                break;
            default:
                badgeClass = 'bg-secondary';
        }
        return '<span class="badge ' + badgeClass + ' status-badge">' + status + '</span>';
    }
    
    // 响应式侧边栏
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebar-toggle');
        const sidebar = document.getElementById('sidebar');
        const content = document.getElementById('main-content');
        const footer = document.querySelector('footer');
        
        if (sidebarToggle && sidebar && content) {
            sidebarToggle.addEventListener('click', function() {
                sidebar.classList.toggle('show');
                
                // 在小屏幕上，切换内容区域的margin
                if (window.innerWidth <= 768) {
                    content.classList.toggle('sidebar-open');
                    if (footer) {
                        if (footer.style.marginLeft === '0px' || !footer.style.marginLeft) {
                            footer.style.marginLeft = 'var(--sidebar-width)';
                        } else {
                            footer.style.marginLeft = '0px';
                        }
                    }
                }
            });
            
            // 窗口大小变化时调整
            window.addEventListener('resize', function() {
                if (window.innerWidth > 768) {
                    sidebar.classList.remove('show');
                    content.classList.remove('sidebar-open');
                    if (footer) footer.style.marginLeft = 'var(--sidebar-width)';
                } else {
                    if (footer) footer.style.marginLeft = '0px';
                }
            });
            
            // 初始化
            if (window.innerWidth <= 768) {
                if (footer) footer.style.marginLeft = '0px';
            }
        }
        
        // 返回顶部按钮
        const backToTopBtn = document.getElementById('back-to-top');
        if (backToTopBtn) {
            window.addEventListener('scroll', function() {
                if (window.pageYOffset > 300) {
                    backToTopBtn.style.display = 'flex';
                    backToTopBtn.style.alignItems = 'center';
                    backToTopBtn.style.justifyContent = 'center';
                } else {
                    backToTopBtn.style.display = 'none';
                }
            });
            
            backToTopBtn.addEventListener('click', function(e) {
                e.preventDefault();
                window.scrollTo({top: 0, behavior: 'smooth'});
            });
        }
        
        // 表格行悬停效果
        const tableRows = document.querySelectorAll('.table tbody tr');
        tableRows.forEach(row => {
            row.addEventListener('mouseenter', function() {
                this.style.backgroundColor = '#f8f9fc';
                this.style.transition = 'background-color 0.2s';
            });
            
            row.addEventListener('mouseleave', function() {
                this.style.backgroundColor = '';
            });
        });
        
            // 添加动画效果到卡片
    const cards = document.querySelectorAll('.card');
    cards.forEach(card => {
        card.style.transition = 'transform 0.2s, box-shadow 0.2s';
        
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-5px)';
            this.style.boxShadow = '0 0.5rem 2rem 0 rgba(58, 59, 69, 0.2)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = '';
            this.style.boxShadow = '0 0.15rem 1.75rem 0 rgba(58, 59, 69, 0.15)';
        });
    });
    
    // 全局加载状态管理
    window.showLoading = function(message = '处理中，请稍候...') {
        const overlay = document.getElementById('loadingOverlay');
        const messageEl = overlay.querySelector('p');
        if (messageEl) messageEl.textContent = message;
        overlay.style.display = 'flex';
    };
    
    window.hideLoading = function() {
        const overlay = document.getElementById('loadingOverlay');
        overlay.style.display = 'none';
    };
    
    // 为所有表单添加加载状态（排除有自定义验证的表单）
    const forms = document.querySelectorAll('form:not([data-custom-validation])');
    forms.forEach(form => {
        form.addEventListener('submit', function() {
            showLoading('提交中，请稍候...');
        });
    });
    
    // 专门处理学生删除表单
    const deleteStudentForms = document.querySelectorAll('.delete-student-form');
    deleteStudentForms.forEach(form => {
        form.addEventListener('submit', function(e) {
            // 防止表单多次提交
            if (this.dataset.isSubmitting === 'true') {
                e.preventDefault();
                return false;
            }
            
            // 标记表单正在提交
            this.dataset.isSubmitting = 'true';
            
            // 显示加载状态
            showLoading('删除中，请稍候...');
        });
    });
    
    // 处理删除学生按钮点击
    const deleteStudentBtns = document.querySelectorAll('.delete-student-btn');
    if (deleteStudentBtns.length > 0) {
        // 获取全局模态框元素
        const deleteModal = document.getElementById('deleteStudentModal');
        
        if (deleteModal) {
            const modal = new bootstrap.Modal(deleteModal);
            const studentNameSpan = document.getElementById('studentNameToDelete');
            const studentIdSpan = document.getElementById('studentIdToDelete');
            const studentIdInput = document.getElementById('deleteStudentId');
            
            deleteStudentBtns.forEach(btn => {
                btn.addEventListener('click', function() {
                    // 获取学生信息
                    const studentId = this.getAttribute('data-student-id');
                    const studentName = this.getAttribute('data-student-name');
                    
                    // 更新模态框内容
                    if (studentNameSpan) studentNameSpan.textContent = studentName;
                    if (studentIdSpan) studentIdSpan.textContent = studentId;
                    if (studentIdInput) studentIdInput.value = studentId;
                    
                    // 显示模态框
                    modal.show();
                });
            });
        }
    }
    
    // 为所有删除按钮添加确认对话框（排除自定义删除按钮）
    const deleteButtons = document.querySelectorAll('.btn-delete, [onclick*="delete"]:not([data-custom-delete])');
    deleteButtons.forEach(button => {
        button.addEventListener('click', function(e) {
            // 如果按钮是模态框触发按钮，不添加确认对话框
            if (button.getAttribute('data-bs-toggle') === 'modal') {
                return;
            }
            
            if (!confirm('确定要删除这条记录吗？此操作不可撤销。')) {
                e.preventDefault();
                return false;
            }
            showLoading('删除中，请稍候...');
        });
    });
});
    </script>
</body>
</html> 