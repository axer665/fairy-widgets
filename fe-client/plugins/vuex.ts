import { createStore, type Store } from "vuex";

type User = { id: number; login: string; email: string; role: string };

type State = {
  token: string | null;
  user: User | null;
};

export default defineNuxtPlugin(() => {
  const initialToken = import.meta.client ? localStorage.getItem("auth_token") : null;

  const store = createStore<State>({
    state: (): State => ({
      token: initialToken,
      user: null,
    }),
    mutations: {
      setAuth(state, payload: { token: string; user: User }) {
        state.token = payload.token;
        state.user = payload.user;
        if (import.meta.client) {
          localStorage.setItem("auth_token", payload.token);
        }
      },
      logout(state) {
        state.token = null;
        state.user = null;
        if (import.meta.client) {
          localStorage.removeItem("auth_token");
        }
      },
    },
  });

  const nuxtApp = useNuxtApp();
  nuxtApp.vueApp.use(store);

  return {
    provide: {
      store: store as Store<State>,
    },
  };
});
