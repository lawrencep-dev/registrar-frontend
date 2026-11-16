<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="X-UA-Compatible" content="ie=edge">
    <title>Login</title>
    <link rel="stylesheet" href="login.css">
</head>
<body>
    <main class="login-layout">
        <section class="login-left" aria-label="LCUPAY Portal">
            <div class="login-left-inner">
                <div class="login-brand">
                    <img src="images/LCUP.png" alt="LCUP school logo" class="login-brand-image">
                    <h1>LCUPAY PORTAL</h1>
                </div>

                <div class="login-story">
                    <div class="login-intro">
                        <h2>Your education.<br>Your payments.</h2>
                        <p>A simple and secure way to manage school fees, track transactions, and stay on top of your account.</p>
                    </div>

                    <section class="login-highlights" aria-labelledby="login-highlights-title">
                        <h3 id="login-highlights-title">Payment essentials, in one place</h3>
                        <ul>
                            <li>
                                <span class="login-highlight-index" aria-hidden="true">01</span>
                                <div>
                                    <h4>School fees</h4>
                                    <p>Keep track of costs and important due dates.</p>
                                </div>
                            </li>
                            <li>
                                <span class="login-highlight-index" aria-hidden="true">02</span>
                                <div>
                                    <h4>Payment history</h4>
                                    <p>Find your transaction details in one place.</p>
                                </div>
                            </li>
                            <li>
                                <span class="login-highlight-index" aria-hidden="true">03</span>
                                <div>
                                    <h4>Student support</h4>
                                    <p>Know where to turn when questions come up.</p>
                                </div>
                            </li>
                        </ul>
                    </section>
                </div>

                <div class="login-promise">
                    <p>SECURE · SIMPLE · CONVENIENT</p>
                </div>
            </div>
        </section>

        <section class="login-right" aria-label="Sign in">
            <header class="login-heading">
                <h2>Welcome back</h2>
                <p>Sign in to access your student account.</p>
            </header>

            <img src="images/LCUP.png" alt="La Consolacion University Philippines seal" class="login-seal">

            <div class="login-form-area">
                <form class="login-card" method="post">
                    <div class="login-field">
                        <label for="login-student-id">Student ID / Email</label>
                        <input id="login-student-id" type="text" name="student_id" placeholder="Enter your student ID or email" autocomplete="username">
                    </div>

                    <div class="login-field">
                        <label for="login-password">Password</label>
                        <input id="login-password" type="password" name="password" placeholder="Enter your password" autocomplete="current-password">
                    </div>

                    <div class="login-options">
                        <label class="login-remember" for="login-remember">
                            <input id="login-remember" type="checkbox" name="remember">
                            <span>Remember me</span>
                        </label>
                        <a href="#" class="login-forgot">Forgot password?</a>
                    </div>

                    <button class="login-submit" type="submit">Sign in</button>
                    <p class="login-register">New to the portal? <a href="#">Contact the registrar</a></p>
                </form>
            </div>

            <p class="login-footer">© 2026 Student Payment Portal</p>
        </section>
    </main>
</body>
</html>