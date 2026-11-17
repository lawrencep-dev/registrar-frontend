const acctFeeList = document.getElementById("acct-fee-list");
const acctPaymentAmount = document.getElementById("acct-payment-amount");
const acctPaymentType = document.getElementById("acct-payment-type");
const acctSelectedFee = document.getElementById("acct-selected-fee");
const acctAmountDue = document.getElementById("acct-amount-due");
const acctDueDate = document.getElementById("acct-selected-due-date");
const acctFeeOptions = Array.from(acctFeeList.querySelectorAll(".acct-fee-option"));

function acctAmountToCents(value) {
    const normalizedValue = value.replace(/[₱,\s]/g, "");
    const match = /^(\d+)(?:\.(\d{0,2}))?$/.exec(normalizedValue);
    if (!match) {
        return null;
    }
    const whole = Number(match[1]);
    const fraction = Number((match[2] || "").padEnd(2, "0"));
    const cents = whole * 100 + fraction;
    return Number.isSafeInteger(cents) ? cents : null;
}

function acctDecimalFromCents(cents) {
    const whole = Math.trunc(cents / 100);
    const fraction = String(cents % 100).padStart(2, "0");
    return `${whole}.${fraction}`;
}

function acctFormatCents(cents) {
    const whole = Math.trunc(cents / 100).toLocaleString("en-US");
    const fraction = String(cents % 100).padStart(2, "0");
    return `₱ ${whole}.${fraction}`;
}

function acctSelectedOption() {
    return acctFeeOptions.find(option => option.querySelector('input[name="charge_id"]').checked) || null;
}

function acctUpdateSummary(cents) {
    const selectedOption = acctSelectedOption();
    if (!selectedOption) {
        return;
    }
    acctSelectedFee.textContent = selectedOption.dataset.feeName;
    acctAmountDue.textContent = acctFormatCents(cents);
}

function acctSetCurrentCharge(option) {
    if (!option) {
        return;
    }
    acctFeeOptions.forEach(feeOption => {
        feeOption.classList.toggle("acct-fee-selected", feeOption === option);
    });
    acctDueDate.textContent = new Date(`${option.dataset.dueDate}T00:00:00`).toLocaleDateString("en-US", {
        month: "short",
        day: "2-digit",
        year: "numeric"
    });
    const remainingCents = acctAmountToCents(option.dataset.remainingBalance);
    if (acctPaymentType.value === "full" && remainingCents !== null) {
        acctPaymentAmount.value = acctFormatCents(remainingCents);
        acctUpdateSummary(remainingCents);
    }
}

function acctSetPaymentType() {
    const isFullPayment = acctPaymentType.value === "full";
    acctPaymentAmount.readOnly = isFullPayment;
    if (isFullPayment) {
        acctSetCurrentCharge(acctSelectedOption());
    }
}

function acctHandleAmountInput() {
    const selectedOption = acctSelectedOption();
    const amountCents = acctAmountToCents(acctPaymentAmount.value);
    const remainingCents = selectedOption ? acctAmountToCents(selectedOption.dataset.remainingBalance) : null;
    if (amountCents === null || remainingCents === null) {
        return;
    }
    if (amountCents >= 100 && amountCents <= remainingCents) {
        acctUpdateSummary(amountCents);
    }
}

function acctFormatPaymentAmount() {
    const selectedOption = acctSelectedOption();
    const remainingCents = selectedOption ? acctAmountToCents(selectedOption.dataset.remainingBalance) : null;
    const amountCents = acctAmountToCents(acctPaymentAmount.value);
    if (remainingCents === null || amountCents === null) {
        return;
    }
    if (amountCents < 100 || amountCents > remainingCents) {
        return;
    }
    acctPaymentAmount.value = acctFormatCents(amountCents);
    acctUpdateSummary(amountCents);
}

acctFeeOptions.forEach(option => {
    option.querySelector('input[name="charge_id"]').addEventListener("change", () => acctSetCurrentCharge(option));
});
acctPaymentType.addEventListener("change", acctSetPaymentType);
acctPaymentAmount.addEventListener("focus", () => {
    if (!acctPaymentAmount.readOnly) {
        const cents = acctAmountToCents(acctPaymentAmount.value);
        if (cents !== null) {
            acctPaymentAmount.value = acctDecimalFromCents(cents);
        }
    }
});
acctPaymentAmount.addEventListener("input", acctHandleAmountInput);
acctPaymentAmount.addEventListener("blur", acctFormatPaymentAmount);
acctSetPaymentType();