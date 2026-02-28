import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['form', 'email', 'submitBtn', 'alert', 'emailError'];

  submit(event) {
    event.preventDefault();

    this.clearUi();
    this.setLoading(true);

    const form = this.formTarget;

    fetch(form.action, {
      method: form.method || 'POST',
      body: new FormData(form),
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json',
      },
      credentials: 'same-origin',
    })
      .then(async (res) => {
        let data = null;
        try {
          data = await res.json();
        } catch (e) {
          // si le serveur renvoie autre chose qu'un JSON
        }

        if (!res.ok) {
          this.handleErrorResponse(data, res.status);
          return;
        }

        this.handleSuccessResponse(data);
      })
      .catch(() => {
        this.showAlert('Erreur réseau. Réessaie.', 'danger');
      })
      .finally(() => {
        this.setLoading(false);
      });
  }

  handleSuccessResponse(data) {
    this.showAlert(data?.message || 'Merci ! Ton email a bien été enregistré 😊', 'success');
    this.formTarget.reset();
    this.emailTarget.classList.remove('is-invalid');

    // Option : scroll doux vers l'ancre (utile si tu submit ailleurs)
    // document.getElementById('preinscription')?.scrollIntoView({ behavior: 'smooth' });
  }

  handleErrorResponse(data, status) {
    const message = data?.message || 'Une erreur est survenue.';
    this.showAlert(message, 'danger');

    const errors = data?.errors || {};

    if (errors.email && errors.email.length) {
      this.setFieldError(errors.email[0]);
    } else if (status === 422) {
      // Erreur validation mais sans champ précis
      // tu peux choisir de laisser juste l'alert
    }
  }

  setFieldError(message) {
    this.emailTarget.classList.add('is-invalid');

    if (this.hasEmailErrorTarget) {
      this.emailErrorTarget.textContent = message;
      this.emailErrorTarget.classList.remove('d-none');
      // bootstrap: invalid-feedback est affiché si champ en is-invalid
      // mais comme on n'est pas forcément dans le bon DOM, on le force visible
    }
  }

  showAlert(message, type = 'success') {
    if (!this.hasAlertTarget) return;

    const el = this.alertTarget;
    el.textContent = message;

    el.classList.remove('d-none', 'alert-success', 'alert-danger', 'alert-warning', 'alert-info');
    el.classList.add('alert', `alert-${type}`);
  }

  clearUi() {
    // alert
    if (this.hasAlertTarget) {
      this.alertTarget.textContent = '';
      this.alertTarget.classList.add('d-none');
      this.alertTarget.classList.remove('alert-success', 'alert-danger', 'alert-warning', 'alert-info');
    }

    // email errors
    if (this.hasEmailErrorTarget) {
      this.emailErrorTarget.textContent = '';
      this.emailErrorTarget.classList.add('d-none');
    }

    if (this.hasEmailTarget) {
      this.emailTarget.classList.remove('is-invalid');
    }
  }

  setLoading(isLoading) {
    if (!this.hasSubmitBtnTarget) return;

    this.submitBtnTarget.disabled = isLoading;

    // Option : feedback texte pendant envoi
    if (isLoading) {
      this.submitBtnTarget.dataset.originalText = this.submitBtnTarget.innerHTML;
      this.submitBtnTarget.innerHTML = 'Envoi...';
    } else if (this.submitBtnTarget.dataset.originalText) {
      this.submitBtnTarget.innerHTML = this.submitBtnTarget.dataset.originalText;
      delete this.submitBtnTarget.dataset.originalText;
    }
  }
}