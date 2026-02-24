(function(){'use strict';

document.addEventListener('DOMContentLoaded',function(){
  var root=document.querySelector('.knx-profile');
  if(!root)return;

  var btns=root.querySelectorAll('.knx-profile__btn');
  Array.prototype.forEach.call(btns,function(b){
    b.addEventListener('click',function(e){/* allow normal navigation */});
  });

  // Change password form handler
  var form = document.getElementById('knxChangePasswordForm');
  if (!form) return;

  var config = window.knxDriverProfile || {};
  if (!config.changePasswordUrl) {
    console.warn('Change password URL not configured');
    return;
  }

  form.addEventListener('submit', function(e) {
    e.preventDefault();

    var btn = document.getElementById('knxChangePasswordBtn');
    var msg = document.getElementById('knxPasswordMessage');
    var currentPassword = document.getElementById('current_password').value.trim();
    var newPassword = document.getElementById('new_password').value.trim();
    var confirmPassword = document.getElementById('confirm_password').value.trim();

    // Clear previous message
    msg.style.display = 'none';
    msg.textContent = '';
    msg.className = 'knx-profile__message';

    // Client-side validation
    if (!currentPassword) {
      showMessage('Please enter your current password', 'error');
      return;
    }

    if (!newPassword) {
      showMessage('Please enter a new password', 'error');
      return;
    }

    if (newPassword.length < 8) {
      showMessage('New password must be at least 8 characters', 'error');
      return;
    }

    if (newPassword !== confirmPassword) {
      showMessage('Passwords do not match', 'error');
      return;
    }

    if (currentPassword === newPassword) {
      showMessage('New password must be different from current password', 'error');
      return;
    }

    // Disable button
    btn.disabled = true;
    btn.textContent = 'Changing...';

    // Prepare request
    var payload = {
      current_password: currentPassword,
      new_password: newPassword,
      confirm_password: confirmPassword,
      knx_nonce: config.knxNonce
    };

    // Make request
    fetch(config.changePasswordUrl, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'X-WP-Nonce': config.wpRestNonce
      },
      body: JSON.stringify(payload),
      credentials: 'same-origin'
    })
    .then(function(res) { return res.json(); })
    .then(function(data) {
      if (data.success) {
        showMessage(data.message || 'Password changed successfully', 'success');
        form.reset();
      } else {
        showMessage(data.message || 'Failed to change password', 'error');
      }
    })
    .catch(function(err) {
      console.error('Change password error:', err);
      showMessage('Network error. Please try again.', 'error');
    })
    .finally(function() {
      btn.disabled = false;
      btn.textContent = 'Change Password';
    });
  });

  function showMessage(text, type) {
    var msg = document.getElementById('knxPasswordMessage');
    if (!msg) return;
    msg.textContent = text;
    msg.className = 'knx-profile__message knx-profile__message--' + type;
    msg.style.display = 'block';
  }
});

})();
