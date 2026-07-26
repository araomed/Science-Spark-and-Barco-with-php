import { useState } from "react";
import { useNavigate } from "react-router-dom";
import api from "./api";

function Login() {
  const [username, setUsername] = useState("");
  const [password, setPassword] = useState("");
  const [error, setError] = useState("");
  const [isSubmitting, setIsSubmitting] = useState(false);
  const navigate = useNavigate();

  const handleSubmit = async (event) => {
    event.preventDefault();
    setError("");
    setIsSubmitting(true);

    try {
      const formData = new URLSearchParams();
      formData.append("username", username);
      formData.append("password", password);

      const response = await api.post("/login", formData, {
        headers: { "Content-Type": "application/x-www-form-urlencoded" },
      });

      localStorage.setItem("token", response.data.access_token);
      navigate("/dashboard", { replace: true });
    } catch {
      setError("Incorrect username or password.");
    } finally {
      setIsSubmitting(false);
    }
  };

  return (
    <main className="login-page">
      <section className="login-panel" aria-label="Science Spark sign in">
        <div className="brand-lockup">
          <div className="brand-mark" aria-hidden="true">
            SS
          </div>
          <div>
            <p className="eyebrow">Science Spark</p>
            <h1>Laboratory Operations</h1>
          </div>
        </div>

        <form className="login-form" onSubmit={handleSubmit}>
          <div className="form-heading">
            <h2>Sign in</h2>
            <p>Access equipment, maintenance, service reports, and dashboards through the PHP API.</p>
          </div>

          <label className="field">
            <span>Username</span>
            <input
              autoComplete="username"
              autoFocus
              onChange={(event) => setUsername(event.target.value)}
              required
              type="text"
              value={username}
            />
          </label>

          <label className="field">
            <span>Password</span>
            <input
              autoComplete="current-password"
              onChange={(event) => setPassword(event.target.value)}
              required
              type="password"
              value={password}
            />
          </label>

          {error ? <p className="form-error">{error}</p> : null}

          <button className="primary-action" disabled={isSubmitting} type="submit">
            {isSubmitting ? "Signing in..." : "Sign in"}
          </button>
        </form>
      </section>
    </main>
  );
}

export default Login;
